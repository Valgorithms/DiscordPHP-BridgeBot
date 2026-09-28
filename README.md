# DiscordPHP-BridgeBot

One Discord bot bridging Twitch, Telegram and YouTube: a chat relay for each,
and one command catalogue reachable from all of them.

```
                 ┌──────────►  twitch.tv/twitchdev
                 │             ◄──────────
#general  ───────┼──────────►  t.me/mygroup
                 │             ◄──────────
                 └             ◄──────────  youtube.com/@yourchannel (live chat)
```

This repository is the application — `bot.php`, the `.env`, and the file the
bridges live in. Everything it does comes from four packages:

| | |
| --- | --- |
| [`vzgcoders/discordphp-bridge`](https://github.com/discord-php/DiscordPHP-Bridge) | routing, persistence, the command catalogue, Components v2, rate limiting |
| [`vzgcoders/discordphp-bridge-twitch`](https://github.com/Valgorithms/DiscordPHP-Bridge-Twitch) | IRC, the whole Helix API, device-code recovery |
| [`vzgcoders/discordphp-bridge-telegram`](https://github.com/Valgorithms/DiscordPHP-Bridge-Telegram) | the long poll, edits, media, panels |
| [`vzgcoders/discordphp-bridge-youtube`](https://github.com/Valgorithms/DiscordPHP-Bridge-YouTube) | live chat into Discord, go-live announcements, the daily quota |

The class reference for all four is at
<https://valgorithms.github.io/DiscordPHP-BridgeBot/>.

## Setup

```bash
composer install
cp .env.example .env   # then fill it in
php bot.php
```

There is a start-to-finish walkthrough in [example.md](example.md) — the first
run, the Twitch and Telegram bridges, and what each failure actually looks like.

A connector is installed **only when its credentials are in `.env`**, so filling
in one section and leaving the other empty is a supported way to run: the bot
says which it skipped and starts anyway.

Three things are easy to miss, and each produces a bridge that looks broken for
a reason nothing tells you:

- **Enable the Message Content intent** on the Discord application page. Without
  it the relay cannot read messages to relay and prefix commands are never
  recognised. Slash commands keep working, so the bot looks alive.
- **Grant the bot Manage Webhooks** in a bridged channel. Without it the relay
  still works, but chat arrives as plain `**name:** message` bot messages
  instead of per-person names and avatars.
- **Turn Telegram's privacy mode off** (`@BotFather` → `/setprivacy` → Disable).
  With it on, the bot only sees messages addressed to it, so a Telegram group
  relays almost nothing.
- **Publish the Google OAuth consent screen.** While it is in *Testing*, Google
  signs the bot out of YouTube every seven days. The bot DMs you when that is
  the case, but nothing else stops working until the day it happens.

### YouTube

YouTube is bridged one way: the stream's live chat comes into Discord, and
Discord does not go out to YouTube. Every message posted to YouTube chat costs
50 of the Google project's 10,000 daily quota units, so relaying a Discord
channel there would spend the day's quota within an hour. The bot posts in
YouTube chat only to answer commands, and when you use `/youtube say`.

It bridges the channel it is signed in as, which is YouTube's rule: the API
reads the live chat of the signed-in account's own broadcasts. What you need:

1. A Google Cloud project with the **YouTube Data API v3** enabled, and its
   OAuth consent screen published.
2. An OAuth client of type **TVs and Limited Input devices**. Its id and secret
   go in `.env` as `YOUTUBE_CLIENT_ID` and `YOUTUBE_CLIENT_SECRET`.
3. On the first start, the bot DMs you a code for `google.com/device`. Enter it
   signed in as the account that streams. The bot keeps the sign-in in `.env`.
4. `/youtube link target:me channel:#stream-chat`.

The [connector's README](https://github.com/Valgorithms/DiscordPHP-Bridge-YouTube)
has the other settings, and what each part costs in quota.

## Installing it

The bot is installed privately: only you can add it to a server. That matters
because whoever can run `link` in *any* server the bot is in can reach every
Twitch channel and Telegram chat the bot can (see
[SECURITY-REVIEW.md](SECURITY-REVIEW.md), S1).

Its install link is <https://www.valgorithms.com/discord.html?app=bridge>. That
page says what the bot asks for and why, then starts Discord's install with
exactly those permissions. The page is built from the
[valgorithms.com](https://github.com/valzargaming/valgorithms.com) repository.

In the [Developer Portal](https://discord.com/developers/applications), for
application `1548742142011121785`, in this order:

1. **Installation → Installation Contexts:** Guild Install only.
2. **Installation → Install Link:** Custom URL,
   `https://www.valgorithms.com/discord.html?app=bridge`. Do this first:
   Discord will not make a bot private while its install link is Discord's own.
3. **Bot → Public Bot:** off.
4. **Bot → Requires OAuth2 Code Grant:** off. Nothing exchanges the code, so
   with this on the bot would never join.
5. **Bot → Message Content Intent:** on.
6. **OAuth2 → Redirects:** add `https://www.valgorithms.com/discord.html`,
   exactly, with `www.` and **without** the `?app=bridge`, then **Save
   Changes**. If Discord answers the install with "Invalid OAuth2
   redirect_uri", this line is missing or differs by a character.

The bot reads these settings back when it connects and warns in the log if
Public Bot or the code grant is on, or if the install page is not a
registered redirect.

It asks for **View Channels, Send Messages, Embed Links, Attach Files, Read
Message History** and **Manage Webhooks** (`536988672`), with `bot` and
`applications.commands`.

Steps 1–3 can be checked without logging in —
`curl -s https://discord.com/api/v10/applications/1548742142011121785/rpc`
should show the custom install link, `"bot_public": false`, and only a `"0"` key
under `integration_types_config`. Other bots can be installed from the same
page; the valgorithms.com README has the steps under *Adding another
application*.

## Commands

Everything is qualified by the network it belongs to — always, on every surface.

```
/bridge   help | about | list | status                     everything, all networks

/twitch   link | here | unlink | list | status | reset      the bridge (admin)
          channel  title | game | tags | info
          stream   uptime | viewers | live | followers | clip | marker
          moderation  ban | unban | timeout | clear | announce | shoutout
                      slow | subonly | emoteonly | followersonly | vip | unvip | mod | unmod
          cast     raid | unraid | commercial
          search | api

/telegram link | here | unlink | list | status | reset      the bridge (admin)
          chat     send | photo | poll | info | pin | unpin
          mod      ban | unban

/youtube  link | here | unlink | list | status | reset      the bridge (admin)
          quota | say
          mod      ban | timeout | unban | delete
```

Each of those works as a Discord slash command, as a Discord prefix command, in
Twitch chat, in Telegram chat, and in YouTube chat, where by default only
moderators get answers because every answer costs quota. `!twitch title Back in ten` typed
in a Telegram group sets the stream title — which is the point of one bot rather
than two. The chat half is one dispatcher in the core, so rank, cooldowns and
"which room did you mean" behave the same in every chat; a command typed in one
network acts on the room of the other that shares a Discord channel with it, and
refuses to guess when there is more than one.

The relay is the same: a Discord channel bridged to a Twitch channel *and* a
Telegram group is one three-way conversation, with each network's messages
carried straight to the other. YouTube chat joins that conversation too, but
nothing is sent back into it.

A chat can drop the group but never the qualifier: `!twitch title` (the long
`!twitch channel title` works too), and never a bare `!title`. That is deliberate. A name
is only free because no connector has claimed it yet, and since every
connector's commands are offered in every chat, an unqualified `!ban` is one
installed package away from meaning two things — with no way for either to know.

Permissions map onto one ladder — everyone, moderator, administrator, operator —
because two platforms' worth would have to be explained twice. Each network
reads the rung off its own idea of rank.

## Surviving a restart

Every bridge lives in `storage/bridges.json`, grouped by connector, and is
reloaded on every start. It is treated as the only record it is: writes are
atomic, the last good copy is kept beside it, and a file that will not parse is
preserved rather than overwritten. Ten seconds after startup the bot checks what
it restored — can it still see each Discord channel, does each room still exist,
is it actually *in* that room — and says what it finds, in the log and in a DM.

Saves happen off the event loop where the host allows it. On Windows without
`ext-uv` that is not possible, so the bot does the write itself and blocks
properly — `fflush()` and `fsync()` — rather than pretending. It logs which it
picked at startup.

## Upgrading from DiscordPHP-TwitchBot or DiscordPHP-TelegramRelay

**Your bridges survive.** A `storage/bridges.json` or `var/relay.json` written by
either old bot is migrated on load into the connector-keyed shape, so copy it
from the old bot's checkout to `storage/bridges.json` and nothing needs
re-running:

```bash
cp ../DiscordPHP-TwitchBot/storage/bridges.json storage/bridges.json
```

**Your `.env` mostly carries over** — the Discord and Twitch keys are unchanged,
and `TELEGRAM_TOKEN` joins them. See `.env.example` for the full list.

**The commands were renamed**, and the old ones are unregistered from Discord
automatically on the first boot where every connector starts. Each connector's
README has the full before/after table.

**Do not run the old bots alongside this one.** They share a Twitch token pair,
and Twitch invalidates the old refresh token on every rotation — whichever
refreshes first locks the other out. Stop the old ones first.

## Building a binary

```bash
composer phpacker
```

Produces `bin/build/bot`. It is gitignored and export-ignored deliberately: the
binary is decompilable and would carry whatever is in `.env` at build time.

## Security

Read [SECURITY-REVIEW.md](SECURITY-REVIEW.md) before adding the bot to a
server you don't run. In short: whoever can run `link` in any server the bot is
in can reach any Twitch channel or Telegram chat the bot can reach, and the
YouTube channel it is signed in as. So turn off
**Public Bot** in the Discord developer portal, and only add the bot where you
trust the admins.

## Licence

MIT.
