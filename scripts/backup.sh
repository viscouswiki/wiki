#!/usr/bin/env bash
#
# Dump the wiki Postgres database and upload it to a private Hugging Face
# dataset repo. Optionally encrypts the dump first (recommended).
#
# Schedule via cron, e.g. daily at 03:00:
#   0 3 * * * cd /home/ubuntu/viscouswiki && ./scripts/backup.sh >> /home/ubuntu/backup.log 2>&1
#
set -euo pipefail

cd "$(dirname "$0")/.."   # -> repo root (the docker compose project dir)

# Read specific values from .env WITHOUT sourcing it (values may contain spaces).
getenv() { grep -m1 "^$1=" ./.env | cut -d= -f2- || true; }
POSTGRES_USER="$(getenv POSTGRES_USER)"
POSTGRES_DB="$(getenv POSTGRES_DB)"
export HF_TOKEN="$(getenv HF_TOKEN)"
export HF_REPO="$(getenv HF_REPO)"
export BACKUP_KEEP="$(getenv BACKUP_KEEP)"
BACKUP_PASSPHRASE="$(getenv BACKUP_PASSPHRASE)"
PYTHON="${BACKUP_PYTHON:-$HOME/.hfvenv/bin/python}"

if [ -z "$HF_TOKEN" ] || [ -z "$HF_REPO" ]; then
  echo "[backup] ERROR: HF_TOKEN and HF_REPO must be set in .env" >&2
  exit 1
fi

TS="$(date -u +%Y%m%d-%H%M%SZ)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

DUMP="$WORK/wiki-$TS.sql.gz"
echo "[backup] $(date -u) dumping database ${POSTGRES_DB}..."
docker compose exec -T db pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" | gzip -9 > "$DUMP"

UPLOAD="$DUMP"
NAME="wiki-$TS.sql.gz"
if [ -n "${BACKUP_PASSPHRASE:-}" ]; then
  echo "[backup] encrypting dump..."
  openssl enc -aes-256-cbc -pbkdf2 -salt -pass "pass:$BACKUP_PASSPHRASE" -in "$DUMP" -out "$DUMP.enc"
  UPLOAD="$DUMP.enc"
  NAME="$NAME.enc"
fi

echo "[backup] uploading $NAME ($(du -h "$UPLOAD" | cut -f1)) to $HF_REPO ..."
"$PYTHON" scripts/hf_upload.py "$UPLOAD" "backups/$NAME"
echo "[backup] done."
