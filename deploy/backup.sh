#!/usr/bin/env bash
# /var/www/Bhabagure/deploy/backup.sh — the nightly backup on the project's own VPS (docs/handover.md §8), run as root by
# bhabaghure-backup.timer. Each night, in /var/backups/bhabaghure (root only):
#
#   daily/<date>/mysql-bhabaghure.sql.gz          the company database (mysqldump --single-transaction)
#   daily/<date>/mysql-bhabaghure_wallet.sql.gz   the wallet database
#   daily/<date>/settings.tar.gz                  api/.env, web/.env.production.local, admin/.env.production.local:
#                                                 APP_KEY and WALLET_KEY are needed to read the encrypted files
#   files/<date>/                                 api/storage/app, hard-linked to the night before (only new files use space)
#
# Kept 14 days. A copy away from this server is Hostinger's own VPS backup, switched on in its panel: these files are on
# the same disk as the data they protect.
#
#   backup.sh          tonight's backup now
#   backup.sh list     what is kept
set -Eeuo pipefail
umask 077

ROOT=/var/www/Bhabagure
API=$ROOT/api
DEST=/var/backups/bhabaghure
KEEP_DAYS=14
TODAY=$(date -u +%F)

die() { printf 'BACKUP FAILED: %s\n' "$*" >&2; exit 1; }
say() { printf '== %s\n' "$*"; }
[[ $EUID -eq 0 ]] || die "run as root."

if [[ ${1:-} == list ]]; then
  du -sh "$DEST"/daily/* "$DEST"/files/* 2>/dev/null || echo "no backups yet"
  df -h / | tail -1
  exit 0
fi

env_value() { sed -n "s/^$1=//p" "$API/.env" | tail -n 1 | sed -e 's/^"//' -e 's/"$//'; }
day=$DEST/daily/$TODAY
mkdir -p "$day" "$DEST/files"
chmod 0700 "$DEST"

for database in "$(env_value DB_DATABASE)" "$(env_value WALLET_DB_DATABASE)"; do
  [[ -n $database ]] || die "a database name is blank in api/.env."
  say "database $database"
  mysqldump --protocol=socket --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces \
    --default-character-set=utf8mb4 "$database" | gzip -6 > "$day/mysql-$database.sql.gz.part"
  gzip -t "$day/mysql-$database.sql.gz.part" || die "the dump of $database is not a valid gzip."
  mv "$day/mysql-$database.sql.gz.part" "$day/mysql-$database.sql.gz"
done

say "settings files"
tar -C "$ROOT" -czf "$day/settings.tar.gz" api/.env web/.env.production.local admin/.env.production.local

say "uploaded files"
previous=$(find "$DEST/files" -mindepth 1 -maxdepth 1 -type d ! -name "$TODAY" | sort | tail -n 1)
rsync -a --delete ${previous:+--link-dest="$previous"} "$API/storage/app/" "$DEST/files/$TODAY/"

say "older than $KEEP_DAYS days: removed"
# By the date in the name, never the folder's own time: rsync -a gives the files snapshot the time of api/storage/app,
# which is older than 14 days, so a time-based clean-up deleted the snapshot it had just made (2026-11-05).
cutoff=$(date -u -d "-$KEEP_DAYS days" +%F)
for dir in "$DEST"/daily/* "$DEST"/files/*; do
  name=$(basename "$dir")
  [[ -d $dir && $name =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ && $name < $cutoff ]] || continue
  printf '   %s\n' "$dir"
  rm -rf -- "$dir"
done

say "done $(date -u +%FT%TZ)"
du -sh "$day" | sed 's/^/   /'
df -h / | tail -1 | sed 's/^/   /'
