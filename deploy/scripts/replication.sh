#!/usr/bin/env bash
# Logical replication to the AI host, and the fallbacks that need no restart.
#
#   deploy/scripts/replication.sh status     # slot lag, subscription state, row parity
#   deploy/scripts/replication.sh resync     # fallback: rebuild the copy from a dump
#   deploy/scripts/replication.sh pause      # stop applying, keep the slot
#   deploy/scripts/replication.sh resume
#   deploy/scripts/replication.sh drop       # roll back to "no replication", keep the data
#   deploy/scripts/replication.sh setup      # idempotent first install
#
# Why this exists (docs/replication.md): enabling logical replication cost exactly
# one restart of work-report-db, for `wal_level=logical`. Everything else here —
# creating the publication, creating or dropping the subscription, refreshing it,
# rebuilding the target from a dump — is ordinary SQL and runs while the application
# keeps serving.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

AI=${WRP_OFFSITE:-AI}
REMOTE_PG=${WRP_REMOTE_PG:-pxspec-pg}
REMOTE_DB=${WRP_REMOTE_DB:-work_report}
LOCAL_DB_CONTAINER=${WRP_DB_CONTAINER:-$WRP_NAME-db}
REMOTE_TABLES_PRESERVE=${WRP_REMOTE_PRESERVE_DB:-work_report_migration_snapshot}

[ -f "$CONF_DIR/env" ] || die "missing $CONF_DIR/env"
# shellcheck disable=SC1091
set -a; . "$CONF_DIR/env"; set +a
[ -f "$CONF_DIR/replica.env" ] || die "missing $CONF_DIR/replica.env (the wr_replica password)"
# shellcheck disable=SC1091
set -a; . "$CONF_DIR/replica.env"; set +a

# SQL travels on stdin in every helper: passing it as an argument loses the quoting
# twice (shell → ssh → podman → psql) and psql then reads the fragments as flags.
local_psql() { podman exec -i "$LOCAL_DB_CONTAINER" psql -U "$DB_USERNAME" -d "$DB_DATABASE" "$@"; }
remote_psql() { ssh -o BatchMode=yes "$AI" "podman exec -i -u postgres $REMOTE_PG psql -d $REMOTE_DB $*"; }

# One query, one line, for both sides: table row counts as "table=count" pairs.
parity() {
    local tables local_rows remote_rows
    tables=$(local_psql -tAc "select tablename from pg_publication_tables where pubname='wr_pub' order by 1")
    local_rows=$(echo "select format('%s=%s', tablename, (xpath('/row/c/text()', query_to_xml(format('select count(*) c from %I.%I', schemaname, tablename), false, true, '')))[1]::text)
        from pg_publication_tables where pubname='wr_pub' order by 1" | local_psql -tA)
    remote_list=$(echo "$tables" | sed "s/.*/'&'/" | paste -sd, -)
    remote_rows=$(echo "select format('%s=%s', tablename, (xpath('/row/c/text()', query_to_xml(format('select count(*) c from %I.%I', schemaname, tablename), false, true, '')))[1]::text)
        from pg_tables where schemaname='public' and tablename in ($remote_list) order by 1" | remote_psql -tA | tr -d ' ')

    local diffs=0
    while IFS= read -r row; do
        [ -n "$row" ] || continue
        if ! grep -qx "$row" <<<"$remote_rows"; then
            echo "  DIFF $row (local) vs $(grep "^${row%%=*}=" <<<"$remote_rows" || echo 'missing')" >&2
            diffs=$((diffs + 1))
        fi
    done <<<"$local_rows"

    return "$diffs"
}

cmd_status() {
    echo "publisher (local)"
    echo "select slot_name, active, wal_status,
        pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn)) as retained_wal
        from pg_replication_slots where slot_name = 'wr_sub'" | local_psql

    echo "subscriber ($AI:$REMOTE_DB)"
    echo "select subname, subenabled, subslotname from pg_subscription where subname = 'wr_sub'" | remote_psql
    echo "select 'tables ready: '||count(*) from pg_subscription_rel where srsubstate = 'r'" | remote_psql -tA

    echo "row parity"
    parity || { echo "  → out of sync; 'resync' rebuilds the copy from a dump (no restart)"; return 1; }
    echo "  all tables match"
}

# The fallback that needs no restart: replace the replicated copy with a dump. Used
# after a schema change (logical replication does not carry DDL) or when the slot was
# invalidated by max_slot_wal_keep_size while the AI host was unreachable.
#
# Three deliberate details:
#   * the dump is written to a file first, so a broken pipe cannot half-restore;
#   * `drop schema public cascade` also drops the extensions that live in it, so they
#     are recreated before the restore;
#   * the restore runs in one transaction (-1), so a failure leaves the subscriber as
#     it was instead of half-empty.
cmd_resync() {
    local dump
    dump=$(mktemp)
    trap 'rm -f "$dump"' RETURN

    log "dumping $DB_DATABASE"
    podman exec "$LOCAL_DB_CONTAINER" pg_dump -U "$DB_USERNAME" --no-owner --no-privileges \
        --no-publications --no-subscriptions "$DB_DATABASE" \
        | grep -vE '^CREATE EXTENSION|^COMMENT ON EXTENSION' > "$dump"
    [ -s "$dump" ] || die "the dump is empty — refusing to touch the subscriber"

    log "dropping subscription and rebuilding $AI:$REMOTE_DB"
    echo "drop subscription if exists wr_sub" | remote_psql -q >/dev/null 2>&1 || true
    clean_stale_slots

    {
        echo "drop schema if exists public cascade;"
        echo "create schema public;"
        echo "grant all on schema public to $DB_USERNAME;"
        echo "create extension if not exists pg_trgm;"
        echo "create extension if not exists unaccent;"
    } | remote_psql -q -v ON_ERROR_STOP=1 >/dev/null

    ssh -o BatchMode=yes "$AI" "podman exec -i -u postgres $REMOTE_PG psql -q -1 -d $REMOTE_DB -v ON_ERROR_STOP=1" \
        < "$dump" >/dev/null

    log "restored $(grep -c '^CREATE TABLE' "$dump") tables; following changes from here"
    # The data came with the dump; the subscription only has to stream what happens
    # next. Writes between the dump and this point are the one gap this path has.
    cmd_subscribe false
    cmd_status
}

# create_slot=false / copy_data=false is what "restore a dump, then follow changes"
# means: the rows are already there (that is what the dump was), so asking the
# subscription to copy them again would collide on every primary key.
cmd_subscribe() {
    local copy_data=${1:-true}
    {
        echo "create subscription wr_sub"
        echo "  connection 'host=$(tailscale ip -4 | head -1) port=$DB_PORT dbname=$DB_DATABASE user=wr_replica password=$WR_REPLICA_PASSWORD'"
        echo "  publication wr_pub"
        echo "  with (copy_data = $copy_data)"
    } | remote_psql -v ON_ERROR_STOP=1 || die "subscription failed (is the slot already there? run drop first)"
}

# Left-over table-sync slots are the failure mode that wedges the fallback: every
# sync worker that dies leaves `pg_<oid>_sync_<oid>_<n>` behind, and once
# max_replication_slots is full no new worker can start — while the worker that could
# not start leaves another one behind. Clean them out before rebuilding.
clean_stale_slots() {
    echo "do \$\$ declare r record; begin
        for r in select slot_name from pg_replication_slots
                 where not active and (slot_name = 'wr_sub' or slot_name like 'pg\\_%\\_sync\\_%')
        loop
            perform pg_drop_replication_slot(r.slot_name);
        end loop;
    end \$\$;" | local_psql -q
}

cmd_setup() {
    log "publisher: role, grants, publication"
    echo "do \$\$ begin
        if not exists (select 1 from pg_roles where rolname = 'wr_replica') then
            create role wr_replica with login replication password '$WR_REPLICA_PASSWORD';
        end if;
    end \$\$;
    grant select on all tables in schema public to wr_replica;
    alter default privileges for role $DB_USERNAME in schema public grant select on tables to wr_replica;
    do \$\$ begin
        if not exists (select 1 from pg_publication where pubname = 'wr_pub') then
            create publication wr_pub for all tables;
        end if;
    end \$\$;" | local_psql -q >/dev/null

    log "subscriber: schema, then the subscription"
    podman exec "$LOCAL_DB_CONTAINER" pg_dump -U "$DB_USERNAME" --schema-only --no-owner --no-privileges \
        --no-publications --no-subscriptions "$DB_DATABASE" \
        | grep -vE '^CREATE EXTENSION|^COMMENT ON EXTENSION' \
        | ssh -o BatchMode=yes "$AI" "podman exec -i -u postgres $REMOTE_PG psql -q -1 -d $REMOTE_DB -v ON_ERROR_STOP=1" >/dev/null

    cmd_subscribe
    cmd_status
}

cmd_pause() { echo "alter subscription wr_sub disable" | remote_psql -q && log "paused (slot kept, WAL still accumulates)"; }
cmd_resume() { echo "alter subscription wr_sub enable" | remote_psql -q && log "resumed"; }

# Rollback: stop replicating. The subscriber keeps whatever it has, which is what the
# dump-based mirror needs if the slot ever becomes a liability.
cmd_drop() {
    echo "drop subscription if exists wr_sub" | remote_psql -q >/dev/null 2>&1 || true
    clean_stale_slots
    local_psql -c "select pg_drop_replication_slot('wr_sub') where exists (select 1 from pg_replication_slots where slot_name = 'wr_sub')" >/dev/null
    log "replication stopped: no subscription, no slot, nothing retained on the publisher"
}

case "${1:-status}" in
    status) cmd_status ;;
    setup) cmd_setup ;;
    resync) cmd_resync ;;
    pause) cmd_pause ;;
    resume) cmd_resume ;;
    drop) cmd_drop ;;
    *) die "usage: $0 [status|setup|resync|pause|resume|drop]" ;;
esac
