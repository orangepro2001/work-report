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
DB_CONTAINER=${WRP_DB_CONTAINER:-$WRP_NAME-db}

stamp=$(date '+%Y%m%d-%H%M%S')
file=$dest/$WRP_NAME-$stamp.sql.gz

log "dumping $DB_DATABASE to $file"
# The database is a container on this host: dumping through it avoids needing a
# client on the host and works the same whether the cluster is local or remote.
podman exec -i "$DB_CONTAINER" \
    pg_dump -U "$DB_USERNAME" --no-owner --no-privileges "$DB_DATABASE" \
    | gzip -6 > "$file"

ls -1t "$dest"/"$WRP_NAME"-*.sql.gz | tail -n +$((keep + 1)) | xargs -r rm -f
log "backup written ($(du -h "$file" | cut -f1))"
