# shellcheck shell=bash
# Shared helpers for the deploy scripts. Sourced, not executed.
#
# Instance parameters (override for a test instance, e.g. WRP_NAME=work-report-test WRP_PORT=3561):
#   WRP_NAME    unit/container/volume prefix   (default work-report)
#   WRP_PORT    bind port on all interfaces    (default 3560)
#   WRP_IMAGE   image repository               (default localhost/$WRP_NAME)
#   WRP_SRC     source tree to build from      (default: this repository)
#   WRP_PREBUILT=1  use the already loaded $WRP_IMAGE:<tag> instead of building

set -euo pipefail

WRP_NAME=${WRP_NAME:-work-report}
WRP_PORT=${WRP_PORT:-3560}
WRP_DB_PORT=${WRP_DB_PORT:-5433}
WRP_IMAGE=${WRP_IMAGE:-localhost/$WRP_NAME}
WRP_SRC=${WRP_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}
CONF_DIR=$HOME/.config/$WRP_NAME
QUADLET_DIR=$HOME/.config/containers/systemd

log() { printf '%s [%s] %s\n' "$(date '+%F %T')" "$WRP_NAME" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

src_version() {
    git -C "$WRP_SRC" rev-parse --short HEAD 2>/dev/null || echo dev
}

build_image() { # <tag>
    if [ "${WRP_PREBUILT:-}" = 1 ]; then
        podman image exists "$WRP_IMAGE:$1" || die "WRP_PREBUILT=1 but $WRP_IMAGE:$1 is not loaded"
        log "using prebuilt $WRP_IMAGE:$1"
        return 0
    fi
    log "building $WRP_IMAGE:$1 from $WRP_SRC"
    podman build -q --format docker -f "$WRP_SRC/deploy/Containerfile" \
        --build-arg VERSION="$1" -t "$WRP_IMAGE:$1" "$WRP_SRC" >/dev/null
}

wait_healthy() { # [seconds]
    local i
    for i in $(seq 1 "${1:-90}"); do
        if curl -fsS -m 2 "http://127.0.0.1:$WRP_PORT/healthz" >/dev/null 2>&1; then
            log "healthy on 127.0.0.1:$WRP_PORT"
            return 0
        fi
        sleep 1
    done
    return 1
}

# render_quadlet writes the unit files for this instance, substituting the
# instance parameters into the checked-in templates.
render_quadlet() {
    mkdir -p "$QUADLET_DIR"
    # The database unit is part of the stack: it must come up before the app.
    # The tailnet address is published only when this host has one — the AI host's
    # subscription needs it, but a machine without tailscale must still render.
    local tailnet_ip publish_line=""
    tailnet_ip=$(tailscale ip -4 2>/dev/null | head -1 || true)
    if [ -n "$tailnet_ip" ]; then
        publish_line="PublishPort=$tailnet_ip:$WRP_DB_PORT:5432"
    fi
    sed -e "s/work-report-db/$WRP_NAME-db/g" \
        -e "s#%h/.config/work-report/db.env#%h/.config/$WRP_NAME/db.env#" \
        -e "s/127.0.0.1:5433/127.0.0.1:$WRP_DB_PORT/" \
        -e "s|@DB_TAILNET_PUBLISH@|$publish_line|" \
        "$WRP_SRC/deploy/quadlet/work-report-db.container" > "$QUADLET_DIR/$WRP_NAME-db.container"
    sed -e "s/work-report-db-data/$WRP_NAME-db-data/g" \
        "$WRP_SRC/deploy/quadlet/work-report-db-data.volume" > "$QUADLET_DIR/$WRP_NAME-db-data.volume"
    sed -e "s/work-report-storage/$WRP_NAME-storage/g" \
        "$WRP_SRC/deploy/quadlet/work-report-storage.volume" > "$QUADLET_DIR/$WRP_NAME-storage.volume"

    sed -e "s/work-report-env/$WRP_NAME-env/g" \
        -e "s/ContainerName=work-report/ContainerName=$WRP_NAME/" \
        -e "s#localhost/work-report:current#$WRP_IMAGE:current#" \
        -e "s/work-report-storage/$WRP_NAME-storage/g" \
        -e "s/0.0.0.0:3560/0.0.0.0:$WRP_PORT/" \
        -e "s#%h/.config/work-report/env#%h/.config/$WRP_NAME/env#" \
        -e "s/systemd-\(.*\)-work-report/systemd-\1-$WRP_NAME/" \
        "$WRP_SRC/deploy/quadlet/work-report.container" > "$QUADLET_DIR/$WRP_NAME.container"
}

# render_backup_units installs the offsite-mirror unit pair. The service calls
# the script in this tree, so the source path is baked in at install time. The
# substitutions run in two passes — name first, path second — so a source path
# that itself contains "work-report" cannot be rewritten by the name pass.
render_backup_units() {
    local dir=$HOME/.config/systemd/user
    mkdir -p "$dir"
    sed -e "s/@NAME@/$WRP_NAME/g" \
        "$WRP_SRC/deploy/systemd/work-report-backup-offsite.service" \
        | sed -e "s|@SCRIPT@|$WRP_SRC/deploy/scripts/backup-offsite.sh|" > "$dir/$WRP_NAME-backup-offsite.service"
    sed -e "s/@NAME@/$WRP_NAME/g" \
        "$WRP_SRC/deploy/systemd/work-report-backup-offsite.timer" > "$dir/$WRP_NAME-backup-offsite.timer"
}

# render_check_units installs the public-entry-point timer: the only thing that notices
# when the host's reverse proxy stops forwarding to this application.
render_check_units() {
    local dir=$HOME/.config/systemd/user
    mkdir -p "$dir"
    sed -e "s/@NAME@/$WRP_NAME/g" \
        "$WRP_SRC/deploy/systemd/work-report-public-check.service" \
        | sed -e "s|@SCRIPT@|$WRP_SRC/deploy/scripts/check-public.sh|" > "$dir/$WRP_NAME-public-check.service"
    sed -e "s/@NAME@/$WRP_NAME/g" \
        "$WRP_SRC/deploy/systemd/work-report-public-check.timer" > "$dir/$WRP_NAME-public-check.timer"
}

# The Yjs sync service (docs/documents.md) is part of the stack, not an extra the
# operator has to remember: rendered with the instance name like everything else.
render_collab_units() {
    mkdir -p "$QUADLET_DIR"
    sed -e "s/work-report-collab/$WRP_NAME-collab/g" \
        -e "s#%h/.config/work-report/collab.env#%h/.config/$WRP_NAME/collab.env#" \
        "$WRP_SRC/deploy/quadlet/work-report-collab.container" > "$QUADLET_DIR/$WRP_NAME-collab.container"
}

build_collab_image() {
    log "building localhost/$WRP_NAME-collab:current"
    podman build -q --format docker -f "$WRP_SRC/deploy/collab/Containerfile" \
        -t "localhost/$WRP_NAME-collab:current" "$WRP_SRC" >/dev/null
}

# Two secrets have to agree across containers: the room-token secret (app ↔ Yjs
# service). One file each, generated once.
ensure_collab_secret() {
    mkdir -p "$CONF_DIR"
    if [ ! -f "$CONF_DIR/collab.env" ]; then
        printf 'COLLAB_SECRET=%s\n' "$(head -c 32 /dev/urandom | base64 | tr -d '/+=' | head -c 43)" > "$CONF_DIR/collab.env"
        chmod 600 "$CONF_DIR/collab.env"
        log "generated $CONF_DIR/collab.env"
    fi

    local secret
    secret=$(sed -n 's/^COLLAB_SECRET=//p' "$CONF_DIR/collab.env")

    if [ -f "$CONF_DIR/env" ] && ! grep -q '^COLLAB_SECRET=' "$CONF_DIR/env"; then
        printf '\n# Shared with the Yjs service; signs the short-lived room token.\nCOLLAB_SECRET=%s\n' "$secret" >> "$CONF_DIR/env"
        log "added COLLAB_SECRET to $CONF_DIR/env"
    fi
}
