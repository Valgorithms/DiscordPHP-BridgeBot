# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

The first public version. DiscordPHP-TwitchBot and DiscordPHP-TelegramRelay are
one bot now, built from four packages: the platform-agnostic core
([`vzgcoders/discordphp-bridge`](https://github.com/discord-php/DiscordPHP-Bridge))
and a connector each for Twitch, Telegram and YouTube. DiscordPHP-TwitchRelay is
retired.

The old bots' repositories now hold those connectors, so they are renamed to
match their packages: DiscordPHP-TwitchBot is
[DiscordPHP-Bridge-Twitch](https://github.com/Valgorithms/DiscordPHP-Bridge-Twitch)
and DiscordPHP-TelegramRelay is
[DiscordPHP-Bridge-Telegram](https://github.com/Valgorithms/DiscordPHP-Bridge-Telegram).
Sibling checkouts go under the new names: `composer.json` looks for
`../DiscordPHP-Bridge-Twitch` and `../DiscordPHP-Bridge-Telegram`, and for the
YouTube side, `../DiscordPHP-Bridge-YouTube` and `../YoutubePHP`.

### Added

- A two-way relay between a Discord channel and a Twitch channel, a Telegram
  group, or both at once — in which case Twitch and Telegram talk to each other
  directly too. Every relayed line names the network it came from.
- One command catalogue served everywhere: `/twitch …` and `/telegram …` in
  Discord, `!twitch …` and `!telegram …` in any chat. Commands are always
  qualified, so a third network cannot shadow one.
- Pictures, GIFs, files, edits and profile pictures carried across wherever the
  destination can show them, and never a URL carrying a bot token.
- Private installation through a custom install page, and a startup check that
  says when the Discord application is set up otherwise.
- A startup check of every restored bridge, reported in the log and by DM.
- Twitch chat that drops, including a connection lost to a network blip that
  never says it closed, reconnects by itself. Messages from Discord wait for it
  meanwhile. When it will not come back, the owner gets a DM with a
  **Reconnect now** button, and the DM says so once chat is back.
- A post in each bridged Discord channel when its Twitch channel goes live,
  with the title and game, and another when the stream ends, with how long it
  ran. A restart mid-stream or a brief encoder drop does not announce it twice.
- YouTube live chat in Discord. Everything said in the signed-in channel's live
  chat, Super Chats and memberships included, arrives in the bridged channels
  under each sender's name and avatar. It is one way: every post to YouTube
  costs quota, so the bot posts there only to answer commands and for
  `/youtube say`. Chat that drops reconnects by itself, with the same
  **Reconnect now** DM as Twitch when it will not come back.
- The same announcements for YouTube as for Twitch, and a ban on YouTube
  removes what the banned person said from Discord too.
- `/youtube quota`, `say`, and `mod ban`, `timeout`, `unban` and `delete`.
  Reading chat keeps back a reserve of the daily quota for them, and the owner
  gets one DM if the quota runs out, saying when chat resumes.
- `SECURITY-REVIEW.md`: who can do what, and what is still open.
- CI on Linux and Windows, and a class reference published to GitHub Pages.
