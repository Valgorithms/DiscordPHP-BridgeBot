# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

The first public version. DiscordPHP-TwitchBot and DiscordPHP-TelegramRelay are
one bot now, built from three packages: the platform-agnostic core
([`vzgcoders/discordphp-bridge`](https://github.com/discord-php/DiscordPHP-Bridge))
and a connector each for Twitch and Telegram. DiscordPHP-TwitchRelay is retired.

The old bots' repositories now hold those connectors, so they are renamed to
match their packages: DiscordPHP-TwitchBot is
[DiscordPHP-Bridge-Twitch](https://github.com/Valgorithms/DiscordPHP-Bridge-Twitch)
and DiscordPHP-TelegramRelay is
[DiscordPHP-Bridge-Telegram](https://github.com/Valgorithms/DiscordPHP-Bridge-Telegram).
Sibling checkouts go under the new names: `composer.json` looks for
`../DiscordPHP-Bridge-Twitch` and `../DiscordPHP-Bridge-Telegram`.

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
- `SECURITY-REVIEW.md`: who can do what, and what is still open.
- CI on Linux and Windows, and a class reference published to GitHub Pages.
