#!/usr/bin/env bash
#
# Back up the MediaWiki MariaDB database + uploaded files (images/) and upload
# the bundle to a private Hugging Face dataset repo. Optionally encrypts first.
#
# Schedule via cron, e.g. daily at 03:00:
#   0 3 * * * cd /home/ubuntu/viscouswiki && ./scripts/backup.sh >> /home/ubuntu/backup.log 2>&1
#
set -euo pipefail

cd "$(dirname "$0")/.."   # -> repo root (the docker compose project dir)

# Read specific values from .env WITHOUT sourcing it (values may contain spaces).
getenv() { grep -m1 "^$1=" ./.env | cut -d= -f2- || true; }
MW_DB_NAME="$(getenv MW_DB_NAME)";     MW_DB_NAME="${MW_DB_NAME:-mediawiki}"
MW_DB_USER="$(getenv MW_DB_USER)";     MW_DB_USER="${MW_DB_USER:-mediawiki}"
MW_DB_PASSWORD="$(getenv MW_DB_PASSWORD)"
export HF_TOKEN="$(getenv HF_TOKEN)"
export HF_REPO="$(getenv HF_REPO)"
export BACKUP_KEEP="$(getenv BACKUP_KEEP)"
BACKUP_PASSPHRASE="$(getenv BACKUP_PASSPHRASE)"
PYTHON="${BACKUP_PYTHON:-$HOME/.hfvenv/bin/python}"

if [ -z "$HF_TOKEN" ] || [ -z "$HF_REPO" ]; then
  echo "[backup] ERROR: HF_TOKEN and HF_REPO must be set in .env" >&2
  exit 1
fi
if [ -z "$MW_DB_PASSWORD" ]; then
  echo "[backup] ERROR: MW_DB_PASSWORD must be set in .env" >&2
  exit 1
fi

TS="$(date -u +%Y%m%d-%H%M%SZ)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "[backup] $(date -u) dumping database ${MW_DB_NAME}..."
docker compose exec -T -e MYSQL_PWD="$MW_DB_PASSWORD" db \
  mariadb-dump --user="$MW_DB_USER" --single-transaction --default-character-set=binary \
  "$MW_DB_NAME" | gzip -9 > "$WORK/db.sql.gz"

echo "[backup] archiving uploaded files (images/)..."
docker compose exec -T mediawiki tar czf - -C /var/www/html/images . > "$WORK/images.tar.gz" 2>/dev/null || \
  echo "[backup] (no images/ or none to archive — continuing)"

# Bundle the DB dump + images into one dated archive.
NAME="viscous-mw-$TS.tar.gz"
DUMP="$WORK/$NAME"
tar czf "$DUMP" -C "$WORK" db.sql.gz images.tar.gz

UPLOAD="$DUMP"
if [ -n "${BACKUP_PASSPHRASE:-}" ]; then
  echo "[backup] encrypting bundle..."
  openssl enc -aes-256-cbc -pbkdf2 -salt -pass "pass:$BACKUP_PASSPHRASE" -in "$DUMP" -out "$DUMP.enc"
  UPLOAD="$DUMP.enc"
  NAME="$NAME.enc"
fi

echo "[backup] uploading $NAME ($(du -h "$UPLOAD" | cut -f1)) to $HF_REPO ..."
"$PYTHON" scripts/hf_upload.py "$UPLOAD" "backups/$NAME"
echo "[backup] done."
