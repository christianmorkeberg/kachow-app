#!/bin/sh
# Dump the database to a gzip file (default: ./backups next to this script); keeps the newest 14. Run from the host's crontab, e.g.
#   20 3 * * *  /srv/kachow/kachow-app/docker/backup.sh
# Copy the folder off the server too (rclone/rsync) — a backup on the same disk is not a backup.
set -e
cd "$(dirname "$0")"
dir="${1:-./backups}"; mkdir -p "$dir"
docker compose exec -T db sh -c 'mariadb-dump --single-transaction --routines -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' | gzip > "$dir/kachow-$(date +%F-%H%M).sql.gz"
ls -1t "$dir"/kachow-*.sql.gz | tail -n +15 | xargs -r rm --
