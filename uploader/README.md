# Viscous Wiki — Media Uploader

A small Node/Express service that lets trusted wiki members upload **large media
(video, audio, big images)** to **Cloudflare R2** and manage what they've
uploaded — all from a single page at **`https://viscous.wiki/upload`**.

It exists because MediaWiki's built-in `Special:Upload` is limited: files pass
through the Cloudflare tunnel and hit Cloudflare's **100 MB proxy cap** (plus
PHP/MediaWiki upload limits), and they land on the VM's local disk. The uploader
sidesteps all of that by having the **browser upload straight to R2**, so it can
handle files up to 5 GB without ever touching this server, the wiki, or the VM's
disk.

---

## The two upload paths (don't confuse them)

| | `Special:Upload` (native MediaWiki) | `/upload` (this uploader) |
|---|---|---|
| **Best for** | screenshots, small images | video / large media |
| **Stored on** | VM local disk (`mw-images` Docker volume) | Cloudflare R2 |
| **Counts against** | VM disk (~41 GB) | R2 10 GB free tier |
| **Size limit** | Cloudflare 100 MB cap + PHP limits | up to 5 GB per file (default) |
| **How bytes travel** | browser → Cloudflare → tunnel → MediaWiki → disk | **browser → R2 directly** |

---

## How it works

### 1. Same-origin, so it can reuse the wiki login

Caddy routes `/upload*` to this service and everything else to MediaWiki, so the
uploader lives on the **same origin** as the wiki (`viscous.wiki`). That means
the visitor's MediaWiki **session cookie is automatically sent** to `/upload`.
There is **no separate login and no shared secret** — MediaWiki is the single
source of truth for who you are.

```
Browser ──/upload──> Caddy ──> uploader ──(forwards your cookie)──> MediaWiki API
   │                                                                     │
   └────────────── presigned PUT URL ──────────────> Cloudflare R2 <─────┘
                    (browser uploads the file directly to R2)
```

### 2. Authorization = MediaWiki group membership

On every protected request the uploader calls MediaWiki's API with the visitor's
cookie:

```
GET  http://mediawiki/api.php?action=query&meta=userinfo&uiprop=groups&format=json
```

- If the response has no logged-in user → **401** ("log in at viscous.wiki first").
- If the user is logged in but **not in the `videouploader` group** → **403**.
- Otherwise the request proceeds.

The `videouploader` group is defined in `mediawiki/LocalSettings.custom.php`.
Grant it to people via **Special:UserRights** (you're a bureaucrat, so you can
add it to yourself and others). Membership is the *only* thing that gates access.

### 3. Direct-to-R2 uploads via presigned URLs

The browser never streams file bytes through this server. Instead:

1. Browser asks the uploader for a presigned URL (`POST /upload/api/presign`
   with `filename`, `contentType`, `size`).
2. The uploader validates the request, checks the R2 quota, and returns a
   short-lived (**15 min**) presigned **`PUT`** URL plus the file's future public
   URL.
3. The browser `PUT`s the file **straight to R2** using that URL.

Because the transfer is browser → R2, it bypasses Cloudflare's 100 MB proxy cap
entirely.

### 4. Storage accounting & housekeeping

- Before minting a presign, the uploader lists the R2 bucket, sums object sizes,
  and **refuses uploads that would exceed the quota** (default 10 GB, the R2 free
  tier) with a `507` and a helpful message.
- The page shows storage used vs. quota and a library of uploaded files (newest
  first), each with its public URL and a delete button.
- Files are stored under the `uploads/` prefix with a UUID-prefixed, sanitized
  name (e.g. `uploads/<uuid>-my_clip.webm`) and served from
  `https://media.viscous.wiki/...`.

---

## API

All `/api/*` routes are relative to `BASE_PATH` (default `/upload`). Everything
except `/api/me` and `/healthz` requires being logged in **and** in the upload
group.

| Method & path | Auth | Purpose |
|---|---|---|
| `GET /api/me` | public | Reports `{ authenticated, authorized, name, group }` so the page can show the right UI. |
| `GET /api/storage` | gated | Returns `usedBytes`, `quotaBytes`, `count`, and the file list. |
| `POST /api/presign` | gated | Validates type/size, checks quota, returns a presigned `PUT` URL + public URL. |
| `POST /api/delete` | gated | Deletes an object by `key` (must be under `uploads/`). |
| `GET /healthz` | public | Liveness check. |

**Validation rules:** content type must match `video/`, `image/`, or `audio/`;
per-file cap is `MAX_UPLOAD_BYTES` (default 5 GiB); delete keys must start with
`uploads/` and contain no `..`.

---

## Configuration (env vars)

Set in `.env` (see `.env.example`); the container reads them at runtime.

| Var | Default | Meaning |
|---|---|---|
| `PORT` | `8080` | Port the service listens on. |
| `BASE_PATH` | `/upload` | URL prefix the page and API mount under. |
| `UPLOAD_GROUP` | `videouploader` | MediaWiki group required to upload/delete. |
| `MEDIAWIKI_API_URL` | `http://mediawiki/api.php` | Internal API the uploader checks the session against. |
| `R2_ACCOUNT_ID` | — | Cloudflare account id (R2 S3 endpoint subdomain). |
| `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` | — | R2 S3 credentials (Object Read & Write). |
| `R2_BUCKET` | `viscous-media` | Target bucket. |
| `R2_PUBLIC_BASE` | `https://media.viscous.wiki` | Public domain files are served from. |
| `R2_QUOTA_BYTES` | `10000000000` | Storage cap (10 GB free tier). |
| `MAX_UPLOAD_BYTES` | `5368709120` | Max size of a single file (5 GiB). |

If the R2 credentials are missing, gated routes return `503` ("not fully
configured") — the wiki keeps working; only the uploader is disabled.

---

## Tech & deployment

- **Node 20 + Express**, dependencies limited to the AWS SDK v3 S3 client and
  presigner (R2 is S3-compatible). No database, no `jsonwebtoken`, no
  `cookie-parser` — auth is delegated entirely to MediaWiki.
- Built from `uploader/Dockerfile` (`node:20-alpine`) and run as the `uploader`
  service in `docker-compose.yml`; Caddy proxies `/upload*` to it on port 8080.
- The front-end is a single static page in `uploader/public/index.html`; the
  server injects `BASE_PATH` into it at render time so all client calls hit the
  right paths.

---

## Security notes

- The uploader trusts MediaWiki's session — it never sees or stores passwords.
- Presigned URLs are short-lived (15 min) and scoped to a single object key.
- R2 credentials live only in `.env` (gitignored), never in the image or repo.
- Deletes are constrained to the `uploads/` prefix so a crafted `key` can't reach
  other objects.
