#!/usr/bin/env bash
# Mirror the local database onto the AI host and keep exactly one file there.
#
#   deploy/scripts/backup-offsite.sh
#   WRP_OFFSITE=AI WRP_OFFSITE_DIR=backups/work-report  (both default as shown)
#
# This is a standby copy, not an archive: the remote file is replaced atomically
# on every run, so the AI host always holds the newest state and nothing else.
# Generations stay on this host — `deploy/scripts/backup.sh` keeps those.
#
# Runs from work-report-backup-offsite.timer (docs/deploy.md). Connection
# parameters come from $CONF_DIR/env so no credential ever reaches a command line.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

OFFSITE=${WRP_OFFSITE:-AI}
offsite_dir=${WRP_OFFSITE_DIR:-backups/work-report}
DB_CONTAINER=${WRP_DB_CONTAINER:-$WRP_NAME-db}

# Last successful dump stays here, so an unreachable AI host still leaves this
# host with the newest copy. 0700 because the dump carries every row.
stage=$HOME/.local/state/$WRP_NAME/offsite
name=$WRP_NAME.sql.gz

[ -f "$CONF_DIR/env" ] || die "missing $CONF_DIR/env"
command -v rsync >/dev/null || die "rsync not found"

# shellcheck disable=SC1091
set -a; . "$CONF_DIR/env"; set +a

# A half-written dump must never replace the good one on either side: dump to
# `.part`, and only mv it into place after compression and gzip -t both pass.
mkdir -p "$stage"
chmod 700 "$stage"
part=$stage/.$name.part
trap 'rm -f "$part"' EXIT

log "dumping $DB_DATABASE from $DB_CONTAINER"
podman exec -i "$DB_CONTAINER" \
    pg_dump -U "$DB_USERNAME" --no-owner --no-privileges "$DB_DATABASE" \
    | gzip -6 > "$part"
gzip -t "$part" || die "$part did not survive gzip -t"
bytes=$(stat -c%s "$part")
log "dump is $(du -h "$part" | cut -f1)"

# rsync writes its own temp file and renames it, so an interrupted transfer never
# shows up as a usable backup; --partial resumes it next run instead.
log "pushing to $OFFSITE:~/$offsite_dir/$name"
rsync -a --partial --timeout=180 \
    -e "ssh -o BatchMode=yes -o ConnectTimeout=15" \
    "$part" "$OFFSITE:$offsite_dir/.$name.part" ||
    die "rsync to $OFFSITE failed — the previous copy on $OFFSITE is untouched"

# Verify on the far side before it becomes the backup: a truncated file that
# replaces a good one is worse than no backup at all.
remote_bytes=$(
    ssh -o BatchMode=yes -o ConnectTimeout=15 "$OFFSITE" "
        set -e
        d=\$HOME/$offsite_dir
        gzip -t \"\$d/.$name.part\"
        mv \"\$d/.$name.part\" \"\$d/$name\"
        chmod 600 \"\$d/$name\"
        stat -c%s \"\$d/$name\"
    "
) || die "$OFFSITE rejected the dump (gzip -t failed); previous copy kept"

[ "$remote_bytes" = "$bytes" ] ||
    die "$OFFSITE has $remote_bytes bytes, dump is $bytes — size mismatch"

mv "$part" "$stage/$name"
log "mirrored $name ($bytes bytes); restore with:"
log "  zcat $stage/$name | podman exec -i $DB_CONTAINER psql -U $DB_USERNAME -d $DB_DATABASE"
