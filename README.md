# DiscordPHP-BridgeBot

One Discord bot bridging Twitch and Telegram: a two-way chat relay for each, and
one command catalogue reachable from all of them.

```
                 ┌──────────►  twitch.tv/twitchdev
#general  ───────┤             ◄──────────
                 └──────────►  t.me/mygroup
                               ◄──────────
```

This repository is the application — `bot.php`, the `.env`, and the file the
bridges live in. Everything it does comes from three packages:

| | |
| --- | --- |
| [`discord-php/bridge`](https://github.com/discord-php/DiscordPHP-Bridge) | routing, persistence, the command catalogue, Components v2, rate limiting |
| [`vzgcoders/discordphp-bridge-twitch`](https://github.com/Valgorithms/DiscordPHP-TwitchBot) | IRC, the whole Helix API, device-code recovery |
| [`vzgcoders/discordphp-bridge-telegram`](https://github.com/Valgorithms/DiscordPHP-TelegramRelay) | the long poll, edits, media, panels |

## Setup

```bash
composer install
cp .env.example .env   # then fill it in
php bot.php
```

There is a start-to-finish walkthrough in [example.md](example.md) — the first
run, both bridges, and what each failure actually looks like.

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
```

Each of those works four ways: as a Discord slash command, as a Discord prefix
command, in Twitch chat, and in Telegram chat. `!twitch title Back in ten` typed
in a Telegram group sets the stream title — which is the point of one bot rather
than two.

A chat drops the group and keeps the qualifier: `!twitch title`, not
`!twitch channel title`, and never a bare `!title`. That is deliberate. A name
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
either old bot is migrated on load into the connector-keyed shape, so copy it to
`storage/bridges.json` and nothing needs re-running:

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

## Licence

MIT.
