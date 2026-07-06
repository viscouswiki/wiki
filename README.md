# Viscous Wiki

Self-hosted [Wiki.js](https://js.wiki/) wiki running on an Oracle Cloud
Always Free ARM VM, fronted by a **Cloudflare Tunnel** so **no inbound ports**
are exposed on the server.

## Stack

| Service        | Image                          | Role                                            |
| -------------- | ------------------------------ | ----------------------------------------------- |
| `db`           | `postgres:16-alpine`           | Database (all wiki content lives here)          |
| `wiki`         | `ghcr.io/requarks/wiki:2`      | Wiki.js app                                     |
| `uploader`     | built from `./uploader`        | R2 media uploader at `/upload` (group-gated)    |
| `caddy`        | `caddy:2-alpine`               | Path router: `/upload*` → uploader, else → wiki |
| `cloudflared`  | `cloudflare/cloudflared:latest`| Outbound tunnel → serves everything via Cloudflare |

```
Visitor ─HTTPS─> Cloudflare ─tunnel─> cloudflared ─> caddy ─┬─> wiki:3000 ─> db
                                                            └─> uploader:8080 ─> R2
```

> The tunnel's public hostname for `viscous.wiki` must point at **`http://caddy:80`**
> (not `wiki:3000`), so Caddy can route `/upload` to the uploader and everything
> else to the wiki on the same origin.

The VM only makes an **outbound** connection to Cloudflare. There are no open
inbound ports, no security-list rules, and no TLS certificates to manage.

## Prerequisites

- An Oracle Cloud (or any) Linux VM with **Docker** + the Compose plugin.
- A domain **added to Cloudflare** (using Cloudflare's nameservers).
- A **Cloudflare Tunnel** created in the Zero Trust dashboard (see below).

### Install Docker (Ubuntu)

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
# log out and back in so the group applies
```

## Setup

### 1. Create the Cloudflare Tunnel

In the Cloudflare **Zero Trust** dashboard:

1. **Networks → Tunnels → Create a tunnel → Cloudflared**, name it, **Save**.
2. Copy the **token** shown in the "Install connector" step (the long string
   after `--token`). You'll paste it into `.env`.
3. Open the tunnel's **Public Hostname** tab → **Add a public hostname**:
   - **Subdomain:** `wiki` (or leave blank for the root domain)
   - **Domain:** your domain
   - **Service:** Type `HTTP`, URL `wiki:3000`

### 2. Clone and configure

```bash
git clone <this-repo-url> viscouswiki
cd viscouswiki
cp .env.example .env
```

Edit `.env` and set:

- `POSTGRES_PASSWORD` — a strong password (see the command in `.env.example`).
- `CLOUDFLARE_TUNNEL_TOKEN` — the token from step 1.

### 3. Launch

```bash
docker compose up -d
docker compose logs -f      # watch startup; Ctrl+C to stop watching
```

### 4. First-run

Visit `https://wiki.<yourdomain>` and complete the Wiki.js setup wizard to
create the admin account.

## Common commands

```bash
docker compose ps                 # status
docker compose logs -f wiki       # tail a service's logs
docker compose restart wiki       # restart a service
docker compose down               # stop everything (data is kept in the volume)
docker compose pull && docker compose up -d   # update to latest images
```

## Backups

All wiki content is in Postgres. `scripts/backup.sh` dumps the database,
optionally encrypts it, and uploads it to a **private Hugging Face dataset repo**
(`HF_REPO`), keeping the newest `BACKUP_KEEP` copies.

### Setup

1. Create a **private** HF dataset repo (or let the script create it) and a
   **write** access token → set `HF_REPO`, `HF_TOKEN` (and ideally
   `BACKUP_PASSPHRASE`) in `.env`.
2. Install the uploader dependency once on the VM:
   ```bash
   sudo apt-get install -y python3-venv
   python3 -m venv ~/.hfvenv && ~/.hfvenv/bin/pip install -U huggingface_hub
   ```
3. Run it manually to test:
   ```bash
   ./scripts/backup.sh
   ```
4. Schedule daily via cron (`crontab -e`):
   ```
   0 3 * * * cd /home/ubuntu/viscouswiki && ./scripts/backup.sh >> /home/ubuntu/backup.log 2>&1
   ```

### Restore

```bash
# 1. Download the backup file from the HF repo (web UI or huggingface-cli download)
# 2. If encrypted (.enc), decrypt with your BACKUP_PASSPHRASE:
openssl enc -d -aes-256-cbc -pbkdf2 -pass "pass:YOUR_PASSPHRASE" \
  -in wiki-YYYYMMDD-HHMMSSZ.sql.gz.enc -out wiki.sql.gz
# 3. Restore into the running stack:
gunzip -c wiki.sql.gz | docker compose exec -T db psql -U wiki -d wiki
```

> The dump contains password hashes and the wiki's signing key — keep the repo
> private and set `BACKUP_PASSPHRASE`.

## Security notes

- **Never commit `.env`** — it holds the DB password and tunnel token. It is
  gitignored; only `.env.example` (placeholders) belongs in the repo.
- The tunnel token grants the ability to serve traffic for your hostname —
  treat it like a password. Rotate it in the Zero Trust dashboard if leaked.
- Wiki content is stored in the database; the `wiki` container is stateless.

## Media uploader (R2)

At **`https://viscous.wiki/upload`**, members of the Wiki.js **`Video Uploaders`**
group can upload videos/large media straight to R2 (browser → R2 via presigned
URL, bypassing Cloudflare's 100 MB proxy cap), see storage usage against the
10 GB free tier, get an embeddable `<video>` snippet, and delete files.

Access is enforced by verifying the visitor's Wiki.js `jwt` cookie against the
wiki's own signing key and checking group membership — no separate login.

### One-time setup

1. **R2 bucket** `viscous-media` with a **custom domain** `media.viscous.wiki`.
2. **R2 API token** (Object Read & Write, scoped to the bucket) → put the
   Account ID / Access Key ID / Secret into `.env` (see `.env.example`).
3. **Bucket CORS policy** (R2 → bucket → Settings → CORS) so the browser may PUT:
   ```json
   [
     {
       "AllowedOrigins": ["https://viscous.wiki"],
       "AllowedMethods": ["PUT"],
       "AllowedHeaders": ["content-type"],
       "ExposeHeaders": ["ETag"],
       "MaxAgeSeconds": 3600
     }
   ]
   ```
4. **Tunnel:** set the `viscous.wiki` public hostname service to **`http://caddy:80`**.
5. Create the **`Video Uploaders`** group in the Wiki.js admin panel and add members.

### Embedding in a page

Paste the copied snippet (e.g. `<video controls width="720" src="…"></video>`).
If the wiki strips it, enable HTML in **Administration → Rendering → Markdown**
(allow HTML), or link to the file directly.
