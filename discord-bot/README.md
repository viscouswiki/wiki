# Viscous Wiki — Discord Reader Bot

A small Node service that reads content out of your Discord server so it can feed
the wiki — built first for the **meta-builds** category, where each thread is a
different build. It has two parts in one process:

1. A **discord.js bot** logged into your server (read-only — it never posts).
2. An **admin web UI** at **`https://viscous.wiki/discord`** (behind HTTP Basic
   auth for now) where you pick *which* channels/categories the bot reads. The
   selection is saved to a data volume and the read content is exposed as JSON.

> **Read-only.** The bot only *reads* channels, threads, and messages. It sends
> nothing to Discord and makes no changes to your server.

---

## What it reads

- **Category** → every readable channel inside it.
- **Forum channel** (▤) → every thread (active + archived). Each thread comes
  back with its title, author, applied **tags**, message count, and an excerpt
  of the starter post. This is the shape of the **meta-builds** posts.
- **Text / announcement channel** → recent messages, plus any threads on it.

Output is structured JSON at `GET /discord/api/content` — ready to be turned into
wiki pages in a later step.

---

## One-time Discord setup

You do this part (I can't create the bot for you):

1. **Create the app + bot:** <https://discord.com/developers/applications> →
   *New Application* → **Bot** tab → *Reset Token* → copy the token into
   `DISCORD_BOT_TOKEN` in `.env`.
2. **Enable the privileged intent:** same Bot tab → *Privileged Gateway Intents*
   → turn on **MESSAGE CONTENT INTENT** (needed to read message/thread text).
3. **Invite the bot** to your server: *OAuth2 → URL Generator* → scopes `bot`,
   permissions **View Channels** + **Read Message History** → open the generated
   URL and add it to your server.
4. *(Optional)* Set `DISCORD_GUILD_ID` to pin a specific server. To get the ID,
   enable **Developer Mode** (Discord *Settings → Advanced*), then right-click the
   server icon → *Copy Server ID*. Leave blank to use the bot's only server.
5. Set `DISCORD_UI_USER` / `DISCORD_UI_PASSWORD` for the admin UI login.

The bot is an **opt-in service** behind the `discord` compose profile, so the
core wiki stack never starts it by accident. Once the env vars are set, deploy
it with:

```bash
docker compose --profile discord up -d --build discord-bot
```

Then open **`https://viscous.wiki/discord`** (this also needs the `/discord*`
route in the Caddyfile and the tunnel pointing at Caddy).

---

## Using the picker

1. Log in with the Basic-auth credentials.
2. The page lists your server's categories and channels. Tick the ones to read —
   ticking a **category** reads everything under it; ticking a **forum** reads all
   its threads.
3. **Save selection.**
4. **Read now →** shows a live preview of what the bot pulled (threads with tags
   and excerpts).

---

## Configuration (env vars)

| Var | Default | Meaning |
|---|---|---|
| `PORT` | `8080` | Port the web UI listens on. |
| `BASE_PATH` | `/discord` | URL prefix the UI + API mount under. |
| `DATA_DIR` | `/data` | Where `selection.json` is persisted (a Docker volume). |
| `DISCORD_BOT_TOKEN` | — | Bot token (required for the bot to log in). |
| `DISCORD_GUILD_ID` | — | Optional server id; blank = first server. |
| `DISCORD_UI_USER` | `admin` | Basic-auth username for the UI. |
| `DISCORD_UI_PASSWORD` | — | Basic-auth password (required, or the UI is disabled). |
| `MAX_THREADS` | `100` | Max threads pulled per forum read. |
| `MAX_MESSAGES` | `50` | Max recent messages pulled per text channel. |

---

## API

All routes are under `BASE_PATH` (default `/discord`) and require Basic auth
except `/healthz`.

| Method & path | Purpose |
|---|---|
| `GET /api/status` | Bot readiness, logged-in user, guild, selected count. |
| `GET /api/tree` | Categories + channels for the picker. |
| `GET /api/selection` | Currently selected channel ids. |
| `PUT /api/selection` | Save `{ channelIds: [...] }`. |
| `GET /api/content` | Read all selected channels → structured JSON. |
| `GET /healthz` | Liveness (no auth). |

---

## Notes & next steps

- **"For now" auth.** The UI uses HTTP Basic auth. A natural next step is to
  delegate to MediaWiki group membership like the media uploader does (forward
  the wiki session cookie, check a group), so there's one login for everything.
- **No writes to the wiki yet.** This scaffold reads and previews. Turning
  meta-build threads into wiki pages (e.g. one page per build under a category)
  is the next milestone — the JSON from `/api/content` is the input for it.
- If the picker is empty, check the bot is actually in the server and has *View
  Channel* permission on those channels; if excerpts are blank, the **Message
  Content Intent** is probably off.
