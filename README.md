# Viscous Wiki

Self-hosted [Wiki.js](https://js.wiki/) wiki running on an Oracle Cloud
Always Free ARM VM, fronted by a **Cloudflare Tunnel** so **no inbound ports**
are exposed on the server.

## Stack

| Service        | Image                          | Role                                            |
| -------------- | ------------------------------ | ----------------------------------------------- |
| `db`           | `postgres:16-alpine`           | Database (all wiki content lives here)          |
| `wiki`         | `ghcr.io/requarks/wiki:2`      | Wiki.js app                                     |
| `cloudflared`  | `cloudflare/cloudflared:latest`| Outbound tunnel → serves the wiki via Cloudflare |

```
Visitor ──HTTPS──> Cloudflare edge ──tunnel──> cloudflared ──> wiki:3000 ──> db
```

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

All wiki content is in Postgres, so back up the database:

```bash
# Create a dump (gitignored by default)
docker compose exec -T db pg_dump -U wiki wiki > backup-$(date +%F).sql

# Restore into a fresh stack
cat backup-YYYY-MM-DD.sql | docker compose exec -T db psql -U wiki -d wiki
```

Keep copies off the VM (e.g. download with `scp`).

## Security notes

- **Never commit `.env`** — it holds the DB password and tunnel token. It is
  gitignored; only `.env.example` (placeholders) belongs in the repo.
- The tunnel token grants the ability to serve traffic for your hostname —
  treat it like a password. Rotate it in the Zero Trust dashboard if leaked.
- Wiki content is stored in the database; the `wiki` container is stateless.

## Roadmap

- **R2 media storage** — a Cloudflare R2 bucket + `media.<domain>` custom domain
  for videos/large files (bypasses Cloudflare's 100 MB upload cap and video ToS).
- **Video uploader** — a small upload page + presign endpoint that lets
  authorized Wiki.js users push large videos straight to R2 and get an
  embeddable link. Access gated by Wiki.js group membership.
