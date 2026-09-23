# Security review

**Scope:** the bridge as deployed from this repository — the core
(`discord-php/bridge`, DiscordPHP-Bridge), the Twitch connector
(DiscordPHP-TwitchBot, branch `bridge`), the Telegram connector
(DiscordPHP-TelegramRelay, branch `bridge`) and this app.
**Date:** 2026-09-23.
**Focus:** who can make the bot do what — in particular, anyone who can reach
an admin-level command they were not meant to.

**Method:** every path that decides a rank, and every path that decides which
room a command acts on, was read end to end on all four surfaces (Discord
prefix, Discord slash, Twitch chat, Telegram chat) and on every button. The
fixes listed below come with regression tests.

This file lists issues that are still open. Keep it out of any public
repository until the open items are resolved or accepted.

---

## At a glance

| ID | Severity | Issue | Status |
| --- | --- | --- | --- |
| S1 | **Critical**\* | Any server that has the bot can link *any* Twitch channel or Telegram chat, then read it, post into it and moderate it | Open: needs a decision |
| S2 | **High** | Rank carries across networks: Telegram admins can moderate the Twitch channel, a Twitch broadcaster can ban in the Telegram group | Open: needs a decision |
| S3 | **High** | Discord roles become moderator powers on Twitch and Telegram (Manage Messages → ban on Twitch) | Open: confirm intended |
| S4 | Medium | Relayed chat is spoken by the bot's moderator account, so it bypasses the channel's own chat restrictions | Open: deployment choice |
| S5 | Medium | `link` does not check that the invoker can see the Discord channel being bridged out | Open: fix recommended |
| S6 | Medium | Local accounts can read the `.env` tokens and modify the code the bot runs | Open: host change |
| S7 | Medium | The Twitch operator is identified by login name, which can change hands | Open: fix recommended |
| S8 | Low | Twitch re-authorization accepts whichever account approves the device code | Open |
| S9 | Low | Chat commands with no cooldown can be spammed | Partly fixed (F4) |
| S10 | Low | Telegram chat ids are shown to everyone, which feeds S1 | Open |
| S11 | Low | A demoted Telegram admin keeps their rank for up to a minute | Accepted |
| S12 | Low | Mentioning a private Discord channel reveals its name to the other network | Open |
| S13 | Bug | The **Unlink** button on the `list` panel has no handler | Open |
| F1 | **High** | `target=` on a prefix command reached *any* room the bot can reach | **Fixed** `f3e75e3`, `03ab92e` |
| F2 | Medium | A Discord nickname could trigger other Twitch bots' commands with moderator authority | **Fixed** `97cadd6` |
| F3 | Medium | Commands from Twitch Shared Chat partners ran in this channel | **Fixed** `97cadd6` |
| F4 | Medium | One flooded Twitch bridge could starve every other bridge | **Fixed** `97cadd6` |

\* S1 is Critical when the bot is in, or can be added to, a server you do not
fully trust. If "Public Bot" is off and the bot is only in your own servers,
treat it as High: it still lets any admin of any of those servers reach every
room the bot can reach.

---

## How access works today

Every command declares one rung. Each surface decides which rung the caller
stands on:

| Rung | Discord (per channel) | Twitch chat | Telegram chat |
| --- | --- | --- | --- |
| **Operator** | `DISCORD_OWNER_ID` | `TWITCH_OWNER_LOGIN` (a login name) | `TELEGRAM_OWNER_ID` |
| **Administrator** | guild owner, **Administrator**, or **Manage Server** | broadcaster | chat creator |
| **Moderator** | **Manage Messages** | moderator | chat administrator, or an anonymous admin |
| **Everyone** | anyone | anyone | anyone |

Rank always comes from the platform itself: Discord's interaction permissions
(which include channel overwrites), Twitch's IRC tags, and Telegram's
`getChatMember`. None of it can be spoofed from a message, and every lookup
that fails falls to **Everyone**.

**What each rung unlocks on the other networks:**

| Rung | Twitch channel | Telegram chat |
| --- | --- | --- |
| Everyone | read title / game / tags / uptime / viewers / followers; search | `send`, `poll`, `info`, member count |
| Moderator | `ban` `unban` `timeout` `clear` `announce` `shoutout` `slow` `subonly` `emoteonly` `followersonly`, `clip`, `marker` | — |
| Administrator | set `title` / `game` / `tags`; `vip` `unvip` `mod` `unmod` `raid` `unraid` `commercial`; `link` `unlink` `reset` | `pin` `unpin` `ban` `unban`, invite link (revokes the old one); `link` `unlink` `reset` |
| Operator | `api`: any Helix endpoint, using the bot's own token | — |

What those commands can actually *do* depends on the bot's standing on the
other network:

- **Twitch `moderator:*` scopes** (the Moderator row) work in any channel where
  the bot account is a moderator.
- **Twitch `channel:*` scopes** (`vip`, `mod`, `raid`, `commercial`, editing
  the title) work only on the channel whose token the bot holds, or where it is
  an editor.
- **Telegram** `pin` and `ban` need the bot to be an admin in that chat.
  `send` and `poll` need only membership.

**The link table is the only boundary** between a Discord server and a room on
another network. A command acts on the room linked to the Discord channel it
was typed in. Every finding below is either a way around that table, or a
question of who gets to write it.

---

## Open findings

### S1 — Linking does not prove control of the room · Critical\*

`/twitch link` and `/telegram link` check three things: that the caller is on
the Administrator rung *in their own server*, that the Discord channel belongs
to that server, and that the room exists. Nothing asks whether that server has
anything to do with the room.

**Scenario.** Somebody owns server M, which has the bot in it.

- `/twitch link target:coffeescrafts`
  - The bot joins that Twitch chat. Everything said in M's channel is now said
    in coffeescrafts' chat, by the bot.
  - If the bot is a moderator there, anyone in M with Manage Messages can run
    `/twitch moderation ban`, `clear`, `slow` and the rest against
    coffeescrafts.
  - If the bot's token is coffeescrafts' own, M's admins can also raid, run
    ads, change the title, and hand out moderator and VIP.
- `/telegram link target:-1001234567890` (any group the bot is in)
  - M now receives **every message in that group**, including private groups.
  - Everyone in M can `send` and `poll` into it.
  - M's admins can pin, unpin, ban, unban, and mint an invite link (which
    revokes the group's real one), wherever the bot is an admin.

**Conditions.** The bot must be in M. If **Public Bot** is enabled in the
Discord developer portal (the default), anyone can add it.

**Options, not mutually exclusive:**

1. **Operator-only linking.** Make `link`/`here` Operator; server admins can
   still `unlink`, `list` and `reset`. Smallest change and closes it outright,
   but every new bridge goes through you.
2. **Guild allowlist.** A `DISCORD_GUILDS=` setting; the bot leaves, or
   ignores, any other server. Closes the "anyone can add it" half.
3. **Prove control from the room itself.** `link` returns a one-time code, and
   the bridge only becomes active when that code is typed in the Twitch/Telegram
   room by someone of Administrator rank *there* (the broadcaster, or the group
   creator). This is the only option that lets untrusted servers self-serve
   safely.

Recommended: turn off Public Bot today; then either (1), or (2) plus (3).

**Update:** the bot is now set up to be installed privately (Public Bot off,
installed from a custom install page), and it warns in its log at startup if
Public Bot is switched back on (`0e91e6f`). That closes "anyone can add it". It
does not close the rest of S1: the admins of every server *you* add it to can
still reach every room the bot can, so options 1–3 still apply as soon as it is
in a server you don't run.

### S2 — Rank carries across networks · High

Commands typed in one network's chat can act on the room of another network
that shares a Discord channel with it (`ChatDispatcher::contextFor`). The
caller keeps the rank they hold **where they typed**, so:

- **Telegram → Twitch.** In a Telegram group bridged (through Discord) with a
  Twitch channel:
  - the group's **administrators** can `!twitch ban`, `timeout`, `clear`,
    `announce`, `slow`, `subonly` and the rest on the Twitch channel;
  - the group's **creator** can also `raid`, run a `commercial`, `mod` and `vip`
    people, and change the title.

  Telegram admin is often handed out freely. Anonymous group admins count as
  moderators too.
- **Twitch → Telegram.** The Twitch **broadcaster** can `!telegram pin`,
  `unpin`, `ban` and `unban` in the Telegram group. Twitch moderators get
  nothing extra today, but would inherit any moderator-level Telegram command
  added later.

This is exactly what the core's own `Access` enum warns against: *"somebody
ends up moderating a Twitch channel from a Telegram group they happen to be an
admin of."*

**Options:**

1. **Cap cross-network rank at Everyone** (recommended). In
   `ChatDispatcher::dispatch`, a command whose qualifier is not the typing
   network's own runs at `min(rank, Everyone)`; the Operator is exempt. About
   ten lines. `!telegram send` from Twitch still works; `!twitch ban` from
   Telegram does not.
2. Make it opt-in per bridge, with the cap as the default.
3. Account linking: a person proves they are the same human on both networks.
   Correct, but a project of its own.

### S3 — Discord roles become remote moderation · High (confirm intended)

The rung a Discord member holds in the channel they type in is the rung they
use on the linked Twitch channel and Telegram group:

- **Manage Messages** → Twitch moderator powers (ban, timeout, clear chat,
  slow mode…). This holds even if it is granted in *one channel only*, as long
  as that channel is the linked one.
- **Manage Server** (not only Administrator) → Administrator rung: raid, ads,
  `mod`/`vip` on Twitch; pin and ban in Telegram.

This is documented behaviour, but a streamer probably doesn't expect that
their Discord server's moderators can ban people from their Twitch chat
through the bot.

**Options:** leave it and say so in the README; require Administrator for
anything that acts on another network; or add a setting that switches it off
(e.g. `BRIDGE_REMOTE_MODERATION=off|admins|moderators`).

### S4 — The bot's moderator status covers relayed text · Medium

If the bot account is a Twitch moderator — needed for every Moderator-row
command — then everything relayed from Discord is spoken by a moderator.
Moderators bypass the channel's slow mode, followers-only and subscriber-only
modes, link filters and AutoMod. Anyone who can post in the linked Discord
channel effectively posts with those exemptions.

**Options:** don't mod the relay account, and give up the Twitch moderation
commands; or use two accounts, one that relays and one that moderates (needs
connector work). At a minimum, document it.

### S5 — `link` doesn't check who can see the channel · Medium

`link channel:#x` checks the caller's rank in the channel they *typed in*, and
that `#x` is in the same server. It does not check that the caller can **see**
`#x`. Manage Server does not imply View Channel, so a member with Manage Server
who is locked out of `#staff` can bridge `#staff` into a public Twitch chat and
read it there.

**Fix:** require View Channel in the destination channel. For a prefix
command, `Permissions::accessIn()` (added for F1) already answers this. For a
slash command, Discord includes the caller's permissions for each picked
channel in the interaction's resolved data.

### S6 — Tokens and code are writable by other local accounts · Medium

`D:\GitHub\DiscordPHP-BridgeBot` and `D:\GitHub\DiscordPHP-TwitchBot` inherit
**Modify** for `NT AUTHORITY\Authenticated Users` and for
`Valithor-Aurora\CodexSandboxUsers` from the root of `D:\`. Any local account
can therefore read the `.env` files (Discord, Twitch and Telegram tokens) and
edit `bot.php` or `vendor/`, which is code execution as whoever runs the bot.
The PHPacker build under `bin/build` embeds the token as well.

**Fix**, from an elevated prompt (review before running; this changes
permissions on your disk):

```bash
icacls "D:\GitHub\DiscordPHP-BridgeBot" /inheritance:r /grant:r "%USERNAME%:(OI)(CI)F" "SYSTEM:(OI)(CI)F" "Administrators:(OI)(CI)F"
```

Do the same for any other directory holding a `.env`. Rotate the tokens if
another person or tool has had access to this machine.

### S7 — The Twitch operator is a login name · Medium

`TWITCH_OWNER_LOGIN` is compared against the sender's login. Twitch logins can
be renamed, and freed names are eventually re-issued. Whoever ends up with the
name becomes Operator in Twitch chat, which means `!twitch api` — any Helix
endpoint, using the bot's token. (Endpoints that return secrets are still
refused in chat.)

**Fix:** add `TWITCH_OWNER_ID`, compare it against the IRC `user-id` tag, and
drop the login setting. Until then, leave `TWITCH_OWNER_LOGIN` empty unless
you actually use `api` from Twitch chat.

### S8 — Re-authorization adopts whichever account approves · Low

When the Twitch token cannot be refreshed, the bot shows a device code, in the
log and by DM to the owner. TwitchPHP stores the token of *whichever* Twitch
account approves that code, without comparing it with `TWITCH_NICK`. Someone
who can read the log within the code's lifetime (about 30 minutes) could
approve it with their own account. Fix in TwitchPHP: validate the new token
and reject a login that doesn't match the configured nick.

### S9 — Command spam · Low (partly fixed)

Only some commands have a cooldown. `!bridge help`, `!bridge status` and
`!twitch status` can be repeated freely. Each costs a reply against the Twitch
account's one budget (18 messages per 30 seconds across every channel). In
Telegram, each command from a new person also costs a `getChatMember` lookup.
F4 now keeps one channel's spam from crowding out the others. What's left is a
per-person command limit in `ChatDispatcher`.

### S10 — Telegram chat ids are shown to everyone · Low

`/telegram status` and the `/telegram chat info` panel are open to everyone
and print the numeric chat id. An id is all S1 needs to bridge a private group
from another server. Showing it only on the Administrator rung shrinks S1's
reach; fixing S1 makes this moot.

### S11 — Telegram rank is cached for a minute · Low (accepted)

`TelegramAdapter` remembers a rank for 60 seconds, so an admin who has just
been demoted keeps their rank that long. This is deliberate: it keeps command
spam from turning into API spam.

### S12 — Private Discord channel names reach the other network · Low

A message that mentions `<#private-channel>` is relayed with the channel's
*name* filled in, even if nobody on the other network could otherwise know it
exists. Fix: use the name only for channels the relayed channel's audience can
see, or fall back to `#channel`.

### S13 — The Unlink button does nothing · Bug

`PanelBuilder::links()` draws an **Unlink** button per row, but no handler is
registered for it, and its id doesn't say which network it's for. Pressing it
makes Discord report that the interaction failed. It is harmless, but when it
is implemented the handler must re-check the Administrator rung and the
server, the way `BridgeActions::confirmed()` does for `reset`.

### Not a vulnerability, but say it out loud

A bridge copies a community's conversation to another platform, whose members
never joined the original: a private Telegram group into a Discord server, or
a Discord channel into a public Twitch chat. Tell each community before
bridging it.

---

## Fixed

### During this review

**F1 — `target=` reached any room · High · `f3e75e3`, `03ab92e` (core).**
Discord prefix commands accepted `target=` or `channel=` as the room to act on,
and checked nothing about it. The simplest abuse needed **no rank at all**:
any member of any server the bot is in could type
`!telegram send hi target=@somegroup` and post into any Telegram group the bot
belongs to. With Manage Messages, the same trick pointed `!twitch ban` at any
channel the bot moderates.

A named room must now be:

- bridged in *this server*, compared by its stored id;
- bridged to a Discord channel the caller can **see**;
- acted on with the **lower** of the caller's rank in the channel where they
  typed and their rank in the channel the room is bridged to.

The operator is exempt. My first version of this fix (`f3e75e3`) checked only
the server, so Manage Messages in any one channel still reached every room the
server bridged, and anyone who could read `#general` could post into a
Telegram group bridged to `#staff`. `03ab92e` closes that.

Options an action declares for itself (`link`'s `target`, `raid`'s `channel`)
are still its own arguments. Slash commands never took an override.
Tests: `DiscordAdapterTest`.

**F2 — Nickname command injection · Medium · `97cadd6` (Twitch).**
A relayed line starts with the speaker's name, which the speaker chooses. The
bot is usually a moderator, and Nightbot, StreamElements and similar run a
line starting with `!` from a moderator. So a Discord or Telegram nickname of
`!addcom !x` created a command. Leading symbols are now stripped from the name.
Tests: `TwitchTextTest`.

**F3 — Shared Chat · Medium · `97cadd6` (Twitch).**
During a Twitch Shared Chat session, partner channels' messages arrive with a
`source-room-id`. Commands from them are no longer run here. Tests:
`TwitchAdapterTest`.

**F4 — One bridge starving the rest · Medium · `97cadd6` (Twitch).**
Introduced by the previous change, which correctly moved to one account-wide
send budget but kept a single FIFO queue. A flooded bridge, or one viewer
spamming commands, could push every other channel's messages out of the
backlog. Channels now take turns, and a full queue drops from the longest
channel. Tests: `TwitchGatewayTest`.

### In the preceding review

| Commit | Fix |
| --- | --- |
| `f382eb4` (core) | `link` accepted a Discord channel from *another* server, so one server could bridge another's channel out. |
| `f382eb4` (core) | `reset` now asks for confirmation, and re-checks the presser's rank on the button. |
| `52c4c0d` (core) | `link` no longer echoes the other network's error text into Discord. |
| `a5ca72b` (Telegram) | Panel buttons act only on chats the pressing server still bridges; the invite button's permission check was a fatal call. |
| `a5ca72b` (Telegram) | Every error leaving the connector has the bot token redacted; file URLs are never logged or posted. |

---

## Checked and holding

- **No pings.** `allowed_mentions` is empty on every Discord send path: webhook
  delivery, its bot-account fallback, prefix and slash replies, and panels. A
  Twitch or Telegram message containing `@everyone`, or a room titled with it,
  pings nobody.
- **No IRC injection.** Every Twitch line has CR/LF and control characters
  stripped. A line can't begin with `/` or `.`, so it is never a Twitch chat
  command.
- **No Telegram HTML injection.** Everything the bot composes is escaped, and
  authors are escaped too.
- **No relay loops.** Webhook messages are never relayed out of Discord; each
  connector drops the bot's own messages.
- **Configuration is Discord-only.** `link`, `unlink`, `reset` and `list` can't
  be run from Twitch or Telegram. `unlink`, `list` and `reset` only ever touch
  the caller's own server.
- **Operator gating.** `/twitch api` is Operator-only. Calls that return
  secrets are refused anywhere public, and every response passes a field-level
  redaction.
- **Secrets stay out of state.** `bridges.json` holds no credentials. Telegram
  errors are redacted before they reach a log or a reply.
- **Failures fail closed.** A rank lookup that fails is Everyone; a room that
  can't be verified is refused.
- **DMs.** The bot doesn't request the `DIRECT_MESSAGES` intent, so prefix
  commands in DMs never reach it.

---

## Deployment checklist

1. Developer portal → Bot → turn **Public Bot** off, so only you can add it to
   a server. The install link becomes the custom page at
   `https://www.valgorithms.com/discord.html?app=bridge`; the README's
   *Installing it* lists every portal setting. The bot warns at startup if
   Public Bot is back on.
2. Put the bot only in servers whose admins you'd trust with every room it can
   reach (S1), and whose moderators you'd trust with Twitch moderation (S3).
3. Only mod the Twitch account, or make the Telegram bot an admin, where you
   want the moderation commands to work (S1, S4).
4. Set `DISCORD_OWNER_ID` and `TELEGRAM_OWNER_ID`. Leave `TWITCH_OWNER_LOGIN`
   empty unless you need `api` from Twitch chat (S7).
5. Lock down the directories holding a `.env` (S6).
6. Tell each community that its chat is bridged, and where to.

## Suggested order of work

1. **S1** — Public Bot off now; then operator-only linking, or an allowlist
   plus a remote confirmation code.
2. **S2** — cap cross-network rank at Everyone.
3. **S6** — tighten the ACLs; rotate the tokens if in doubt.
4. **S7**, **S5**, **S3** (decide), then **S8**, **S10**, **S13**.
