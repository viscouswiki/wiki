# Viscous Wiki

Self-hosted **[MediaWiki](https://www.mediawiki.org/)** for the Deadlock
character *Viscous*, running on an Oracle Cloud Always Free ARM VM, fronted by a
**Cloudflare Tunnel** so **no inbound ports** are exposed on the server.

## Stack

| Service       | Image                            | Role                                             |
| ------------- | -------------------------------- | ------------------------------------------------ |
| `db`          | `mariadb:11`                     | Database (all wiki content lives here)           |
| `mediawiki`   | `mediawiki:1.43`                 | MediaWiki app (Citizen + Fluent skins)           |
| `uploader`    | built from `./uploader`          | R2 media uploader at `/upload` (group-gated)     |
| `caddy`       | `caddy:2-alpine`                 | Path router: `/upload*` → uploader, else → wiki  |
| `cloudflared` | `cloudflare/cloudflared:latest`  | Outbound tunnel → serves everything via Cloudflare |

```
Visitor ─HTTPS─> Cloudflare ─tunnel─> cloudflared ─> caddy ─┬─> mediawiki:80 ─> db (MariaDB)
                                                            └─> uploader:8080 ─> R2
```

> The tunnel's public hostname for `viscous.wiki` must point at **`http://caddy:80`**
> so Caddy can route `/upload` to the uploader and everything else to MediaWiki
> on the same origin (which lets the uploader read the visitor's MediaWiki
> session cookie).

The VM only makes an **outbound** connection to Cloudflare — no open inbound
ports, no security-list rules, no TLS certificates to manage.

## Prerequisites

- An Oracle Cloud (or any) Linux VM with **Docker** + the Compose plugin, and **git**.
- A domain **added to Cloudflare**.
- A **Cloudflare Tunnel** (Zero Trust dashboard) whose public hostname
  `viscous.wiki` points at `http://caddy:80`.

## Setup

### 1. Clone and configure

```bash
git clone <this-repo-url> viscouswiki
cd viscouswiki
cp .env.example .env          # then edit .env (see comments in the file)
./scripts/install-skins.sh    # clones the Citizen + Fluent skins into ./skins
```

### 2. Install MediaWiki (first run only)

Bring up the database, generate `LocalSettings.php` non-interactively, then
start the full stack:

```bash
set -a; . ./.env; set +a
docker compose up -d db
# wait until: docker inspect -f '{{.State.Health.Status}}' viscouswiki-db-1  == healthy

docker run --rm --network viscouswiki_default -v "$PWD/mediawiki:/conf" mediawiki:1.43 bash -c "
  php maintenance/install.php \
    --dbtype=mysql --dbserver=db --dbname=\"$MW_DB_NAME\" \
    --dbuser=\"$MW_DB_USER\" --dbpass=\"$MW_DB_PASSWORD\" \
    --server='https://viscous.wiki' --scriptpath='' --lang=en \
    --pass=\"$MW_ADMIN_PASSWORD\" 'The Viscous Wiki' Admin &&
  printf '\n\nrequire_once \"\$IP/LocalSettings.custom.php\";\n' >> LocalSettings.php &&
  cp LocalSettings.php /conf/LocalSettings.php"

docker compose up -d                                             # start everything
docker compose exec -T mediawiki php maintenance/run.php update --quick
```

### 3. Apply the green theme

```bash
docker compose exec -T mediawiki php maintenance/edit.php \
  -u Admin -s "Viscous green" "MediaWiki:Common.css" < mediawiki/Common.css
```

Visit **https://viscous.wiki**. Log in as `Admin` (password = `MW_ADMIN_PASSWORD`).

## Skins & theme

- **Citizen** is the default skin, themed Viscous-green by shifting its OKLCH
  accent hue (blue → green) in `MediaWiki:Common.css` (source: `mediawiki/Common.css`).
- **Fluent** is installed as an alternative — switch it per-account in
  **Preferences → Appearance → Skin**.
- Non-secret site config lives in `mediawiki/LocalSettings.custom.php`
  (pretty URLs, skins, the uploader group, uploads). It is `require`d from the
  generated `LocalSettings.php`.

## Media uploader (R2)

At **`https://viscous.wiki/upload`**, members of the MediaWiki **`videouploader`**
group upload videos/large media straight to R2 (browser → R2 via presigned URL,
bypassing Cloudflare's 100 MB proxy cap), see storage used against the 10 GB free
tier, get an embeddable `<video>` snippet, and delete files.

**How access is enforced:** the uploader forwards the visitor's MediaWiki session
cookie to `api.php?meta=userinfo&uiprop=groups` and only proceeds if they're
logged in and in the `videouploader` group — no separate login, no shared secret.

**Granting access:** the `videouploader` group is defined in
`LocalSettings.custom.php`. Add members via **Special:UserRights** (you are a
bureaucrat, so you can grant it to yourself and others).

## Backups

`scripts/backup.sh` dumps the MariaDB database **and** the uploaded `images/`
directory, bundles them, optionally encrypts (AES-256), and uploads to a private
**Hugging Face dataset repo** (`HF_REPO`), keeping the newest `BACKUP_KEEP` copies.

```bash
# one-time: install the uploader dependency on the VM
sudo apt-get install -y python3-venv
python3 -m venv ~/.hfvenv && ~/.hfvenv/bin/pip install 'huggingface_hub==0.23.5'

./scripts/backup.sh                                   # run once to test
# daily via cron (crontab -e):
# 0 3 * * * cd /home/ubuntu/viscouswiki && ./scripts/backup.sh >> /home/ubuntu/backup.log 2>&1
```

### Restore

```bash
# 1. Download the bundle from the HF repo.
# 2. If encrypted (.enc), decrypt with your BACKUP_PASSPHRASE:
openssl enc -d -aes-256-cbc -pbkdf2 -pass "pass:YOUR_PASSPHRASE" \
  -in viscous-mw-YYYYMMDD-HHMMSSZ.tar.gz.enc -out bundle.tar.gz
# 3. Unpack and restore:
tar xzf bundle.tar.gz                                 # -> db.sql.gz, images.tar.gz
set -a; . ./.env; set +a
gunzip -c db.sql.gz | docker compose exec -T -e MYSQL_PWD="$MW_DB_PASSWORD" db \
  mariadb --user="$MW_DB_USER" "$MW_DB_NAME"
docker compose exec -T mediawiki bash -c 'tar xzf - -C /var/www/html/images' < images.tar.gz
```

## Common commands

```bash
docker compose ps                       # status
docker compose logs -f mediawiki        # tail a service's logs
docker compose restart mediawiki        # restart a service
docker compose pull && docker compose up -d   # update images
```

## Security notes

- **Never commit `.env`** or `mediawiki/LocalSettings.php` — both hold secrets
  and are gitignored. Only `.env.example` belongs in the repo.
- The tunnel token grants the ability to serve traffic for your hostname — treat
  it like a password; rotate it in the Zero Trust dashboard if leaked.
- Encrypt backups (`BACKUP_PASSPHRASE`) — the dump contains password hashes and
  the wiki's secret keys.
