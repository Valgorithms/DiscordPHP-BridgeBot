# A worked example

Start to finish: one Discord server, one Twitch channel, one Telegram group,
and the two of them reachable from each other. Every command and every line of
output below is what the bot actually prints — nothing here is illustrative.

The [README](README.md) covers what each setting means. This covers what
happens when you use them.

---

## What you end up with

```
                 ┌──────────►  twitch.tv/coffeescrafts
#stream-chat ────┤             ◄──────────
                 └──────────►  t.me/craftsgroup
                               ◄──────────
```

Anything said in `#stream-chat` reaches both. Anything said in either reaches
`#stream-chat`, through a webhook, under the speaker's own name and avatar. And
`!twitch title Back in ten` works from all three.

---

## 1. Fill in `.env`

```bash
composer install
cp .env.example .env
```

The minimum for both networks is five values:

```ini
DISCORD_TOKEN=…
DISCORD_OWNER_ID=…          # your Discord user id

TWITCH_CLIENT_ID=…
TWITCH_NICK=yourname        # YOUR Twitch login — the bot speaks as you, not as the app
TWITCH_ACCESS_TOKEN=…       # for your account: chat:read, chat:edit
TWITCH_REFRESH_TOKEN=…

TELEGRAM_TOKEN=…            # from @BotFather
```

Leave either network's section empty and the bot runs without it. That is a
supported way to run, not a degraded one — it says which it skipped and carries
on.

Whoever hosts the bot runs it as **their own** Twitch account. The application
in the Twitch developer console only supplies the client id; it is not an
account and cannot chat. So on Twitch everything the bridge relays comes from
your account, commands that change a channel (title, game, raids, VIPs) work on
your own channel, and moderation works wherever you are a moderator. What you
type in Twitch chat is relayed like anyone else's.

### The three that bite

Each of these produces a bridge that looks broken for a reason nothing tells
you, so they are worth doing before the first run rather than after:

- **Message Content intent**, on the Discord application page. Without it the
  relay reads every message as empty and prefix commands are never recognised.
  Slash commands keep working, so the bot looks perfectly alive.
- **Manage Webhooks**, in the channel you are going to bridge. Without it the
  relay still works, but everything arrives as `**name:** message` from the bot
  instead of under each person's own name and picture.
- **Telegram privacy mode off** — `@BotFather` → `/setprivacy` → Disable, then
  **remove and re-add the bot to the group** (the setting only applies from
  when it joins). With it on, the bot cannot see ordinary group messages at
  all, and the Telegram → Discord direction is simply silent.

---

## 2. First run

```bash
php bot.php
```

Abridged, and annotated:

```
[12:04:01] INFO: [bridge] starting with 2 connector(s): twitch, telegram
[12:04:03] INFO: [twitch] chat connected as yourname
[12:04:03] INFO: [twitch] ready as yourname; 0 bridge(s) configured, commands start with !
[12:04:03] INFO: [telegram] polling as @MyBridgeBot; 0 bridge(s) configured, commands start with !
[12:04:03] INFO: [slash] 3 command(s) over 53 action(s): 0 unchanged, 3 written
[12:04:03] INFO: [slash] registered /bridge
[12:04:03] INFO: [slash] registered /twitch
[12:04:03] INFO: [slash] registered /telegram
[12:04:03] INFO: [bridge] 53 action(s) across 2 connector(s)
[12:04:03] INFO: [bridge] no bridges configured yet (storage/bridges.json)
[12:04:03] INFO: [bridge] filesystem: synchronous (no ext-uv or ext-eio) — writes are
                 durable but briefly block the loop; install php-uv for non-blocking disk I/O
```

Three lines are worth reading rather than skimming:

- **`0 unchanged, 3 written`** — the first boot writes all three commands. Every
  boot after this says `3 unchanged, 0 written`, because each definition is
  compared against what Discord already has. If you ever see a write you did not
  expect, something in the command tree changed.
- **`commands start with !`** — once per chat network. Every chat is offered
  the *whole* catalogue, the other network's included: `!telegram send hi` works
  in Twitch chat. `!twitch` or `!telegram` on its own lists what can be run
  there; the handful that are missing are the ones only Discord can serve.
- **`filesystem: synchronous`** — on Windows without `ext-uv` there is no async
  backend, so the bot does the write itself and blocks *properly*: `fflush()`
  and `fsync()`. Roughly 3.5 ms per save. It says so at every start rather than
  letting you assume otherwise.

A global slash command can take up to an hour to appear the first time. It is
registered; Discord is propagating it.

---

## 3. Bridge a Twitch channel

In `#stream-chat`:

```
/twitch here target:coffeescrafts
```

> <#1549845016887951550> is now bridged with **CoffeesCrafts**
> (https://twitch.tv/coffeescrafts).

`here` bridges the channel you are in. `/twitch link target:… channel:#other`
does it from somewhere else.

The name is checked before anything is wired up. A typo does not produce a
bridge that silently never works:

```
/twitch here target:coffescrafts
```

> there is no Twitch room called `coffescrafts`. Check the spelling — a bridge
> to a room that does not exist looks exactly like one that is simply quiet.

And if the bot cannot post where you have just pointed it, it says so now
rather than when the first message vanishes:

> <#123> is now bridged with **CoffeesCrafts**.
> -# I am missing **Manage Webhooks** there. Without Send Messages nothing
> arrives at all; without Manage Webhooks it arrives as plain bot messages
> instead of per-person names and avatars.

The log confirms the join:

```
[12:06:12] INFO: [twitch] joined #coffeescrafts
[12:06:12] INFO: [bridge] twitch synced (+1 / -0), now following 1
```

**Try it.** Say something in `#stream-chat`; it appears in Twitch chat, sent
from your account, as `yourdiscordname (discord): hello`. Say something in
Twitch chat; it appears in `#stream-chat` under that person's Twitch name and
avatar, with `(twitch)` after it. Every relayed line names the network it came
from, so nobody is mistaken for a member of the chat it lands in.

The same goes for what a command posts on someone's behalf from another
network: `/telegram chat send` from Discord arrives as
`yourdiscordname (discord): …`, and a `/twitch moderation announce` typed in
Discord or Telegram is prefixed with who sent it, since Twitch shows every
announcement as yours.

---

## 4. Bridge a Telegram group

Add the bot to the group first — it cannot let itself in. Then, in the same
Discord channel:

```
/telegram here chat:@craftsgroup
```

> <#1549845016887951550> is now bridged with **Crafts Group**
> (https://t.me/craftsgroup).

A numeric id works too (`-1001234567890`), and so does a `t.me/…` link. A
private *invite* link does not, and says why:

> `https://t.me/+AbCdEf` does not look like a Telegram room.

That is not a parsing failure — an invite link carries no chat id, and there is
no way to turn one into an id from outside. Use the group's `@username`, or get
its numeric id from any relayed message.

`#stream-chat` is now bridged to **both**. That is the point of keying bridges
by connector: one Discord channel, one room per network, no collision.

It is one conversation, not two that only Discord can see. Twitch chat reaches
the Telegram group and Telegram reaches Twitch chat, each labelled with where it
came from:

```
CoffeeFan (twitch): is the stream back?
```

The hop is made directly, from the original message. Discord cannot make it: its
copy arrives through a webhook, and webhook messages are never relayed onward —
that rule is what stops the bridge echoing itself forever.

---

## 5. Drive Twitch from Telegram

This is what one bot buys over two. In the Telegram group:

```
!twitch title Back in ten
```

> title set to: Back in ten

The command is qualified — `!twitch title`, never a bare `!title` — and that is
deliberate. `title` is free only because no other connector has claimed it; the
moment one does, an unqualified name means two things with no way for either to
know. Typing `!twitch` alone lists what is under it.

It works the other way round too. In Twitch chat:

```
!telegram send Stream's back up
```

> ✅ Sent to **Crafts Group**.

And in Discord, the same commands are slash commands with typed options:

```
/twitch channel title text:Back in ten
/telegram chat poll question:Next game? options:Factorio, Rimworld, Dwarf Fortress
```

A chat drops the group (`!twitch title`); Discord keeps it
(`/twitch channel title`). Same handler, same arguments, both ways.

---

## 6. Check on it

```
/twitch list
```

```
**Twitch bridges in this server**
1. <#1549845016887951550> ⇄ **CoffeesCrafts** `coffeescrafts`
-# Last bridge check: 2 of 2 bridges working
```

```
/twitch status
```

````
**Twitch bridge**
```
bridged with  CoffeesCrafts
id            coffeescrafts
connected     yes
queued        0
last check    2 of 2 bridges working
```
````

The same thing in Twitch chat, where there is no code block and 500 characters
to work with:

```
!twitch status
```

> Twitch bridge — bridged with: CoffeesCrafts · connected: yes · queued: 0

And across everything at once:

```
/bridge status
```

````
**Status**
```
bridges        2
twitch         1 room(s), 0 queued
telegram       1 room(s), 0 queued
discord queue  0
last check     2 of 2 bridges working
storage        filesystem: synchronous (no ext-uv or ext-eio) — …
```
````

---

## 7. Restart it

```
^C
[12:40:55] INFO: [bridge] shutting down
```

Ctrl-C flushes the store before the loop stops — a queued write would otherwise
never run, and the change somebody just made would be the one lost.

Start it again:

```
[12:41:02] INFO: [bridge] restored 2 bridges across 1 server (telegram, twitch) from storage/bridges.json
[12:41:02] INFO: [slash] 3 command(s) over 53 action(s): 3 unchanged, 0 written
[12:41:12] INFO: [bridge] 2 of 2 bridges working
```

Nothing to set up again. Ten seconds in — late enough for the guild caches to
settle and the joins to land — the bot checks what it restored: can it still see
each Discord channel, does each room still exist, and, the one a restart is
specifically meant to re-establish, is it actually *in* that room?

A join that silently failed leaves a bridge that works in one direction only,
which is invisible from the configuration alone. So when something is wrong it
says which, in the log and in a DM to `DISCORD_OWNER_ID`:

```
[12:41:12] WARNING: [bridge] 1 of 3 bridges working
[12:41:12] WARNING: [bridge] channel 1201001117027405874 ⇄ twitch valgorithms: the room
           exists but I am not in it, so nothing will arrive from it.
[12:41:12] WARNING: [bridge] channel 999 ⇄ telegram -1001234567890: I can't see that
           Discord channel any more. Anything said on the other side has nowhere to go.
```

**Nothing is ever pruned automatically.** A guild can be briefly unavailable
during a Discord outage, and deleting somebody's configuration over a bad ten
seconds is far worse than telling them about it.

---

## When it looks broken

| What you see | What it is |
| --- | --- |
| Discord → elsewhere works, the other way is silent | Telegram privacy mode, or the bot was never added to the Twitch channel's chat. `status` says `connected: no`. |
| Everything relays as `**name:** message` from the bot | No **Manage Webhooks** in that channel. |
| Relayed messages are empty | No **Message Content** intent. |
| Prefix commands do nothing, slash commands work | Same: no Message Content intent. |
| `!title` does nothing | It is `!twitch title`. Commands are always qualified. |
| A command is missing from Discord's menu | A global command takes up to an hour to propagate on first registration. |
| Twitch stops relaying after about four hours | No `TWITCH_CLIENT_SECRET`, so the token cannot be refreshed. Check your DMs for a `twitch.tv/activate` code. |
| Telegram never connects, on Windows | No usable CA bundle. The `[telegram] verifying TLS …` line at startup says what was used; set `TELEGRAM_CA_BUNDLE` to a `cacert.pem`, or name one in php.ini as `openssl.cafile` or `curl.cainfo`. |

Two failures are worth recognising by name, because the bot recovers from both
and says so:

**A damaged configuration file.** If `storage/bridges.json` will not parse, the
backup beside it is tried; if that fails too the file is kept as
`bridges.json.corrupt-<timestamp>` and the bot starts with none of it rather
than overwriting it on the next `link`. Either way you get a DM.

**A connector that will not start.** The others carry on, and the log says what
is unavailable. The one thing the bot will *not* do in that state is remove
stale commands — pruning against an incomplete list would delete the working
commands of whichever package happened to fail:

```
[12:41:03] INFO: [bridge] not removing stale commands: something did not start,
           so the list is incomplete
```

---

## Taking a bridge down

```
/twitch unlink
```

> <#1549845016887951550> is no longer bridged with **coffeescrafts**.

Or everything this server has, on one network:

```
/twitch reset
```

> cleared 1 Twitch bridge in this server.

Both leave the *other* network's bridge in that channel alone.
