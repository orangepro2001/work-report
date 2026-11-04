#!/usr/bin/env bash
# Dump the database and keep the newest N dumps.
#   deploy/scripts/backup.sh [keep]
# Reads the connection parameters from $CONF_DIR/env, so no credential is ever on
# a command line where `ps` could see it.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

keep=${1:-14}
dest=${WRP_BACKUP_DIR:-$HOME/backups/$WRP_NAME}
mkdir -p "$dest"

# shellcheck disable=SC1091
set -a; . "$CONF_DIR/env"; set +a

stamp=$(date '+%Y%m%d-%H%M%S')
file=$dest/$WRP_NAME-$stamp.sql.gz

log "dumping $DB_DATABASE to $file"
PGPASSWORD=$DB_PASSWORD podman run --rm -i \
    -e PGPASSWORD \
    docker.io/library/postgres:18-alpine \
    pg_dump "host=$DB_HOST port=$DB_PORT dbname=$DB_DATABASE user=$DB_USERNAME sslmode=$DB_SSLMODE" \
    --no-owner --no-privileges | gzip -6 > "$file"

ls -1t "$dest"/"$WRP_NAME"-*.sql.gz | tail -n +$((keep + 1)) | xargs -r rm -f
log "backup written ($(du -h "$file" | cut -f1))"
