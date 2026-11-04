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
    sed -e "s/work-report-db/$WRP_NAME-db/g" \
        -e "s#%h/.config/work-report/db.env#%h/.config/$WRP_NAME/db.env#" \
        -e "s/127.0.0.1:5433/127.0.0.1:$WRP_DB_PORT/" \
        "$WRP_SRC/deploy/quadlet/work-report-db.container" > "$QUADLET_DIR/$WRP_NAME-db.container"
    sed -e "s/work-report-db-data/$WRP_NAME-db-data/g" \
        "$WRP_SRC/deploy/quadlet/work-report-db-data.volume" > "$QUADLET_DIR/$WRP_NAME-db-data.volume"

    sed -e "s/work-report-env/$WRP_NAME-env/g" \
        -e "s/ContainerName=work-report/ContainerName=$WRP_NAME/" \
        -e "s#localhost/work-report:current#$WRP_IMAGE:current#" \
        -e "s/0.0.0.0:3560/0.0.0.0:$WRP_PORT/" \
        -e "s#%h/.config/work-report/env#%h/.config/$WRP_NAME/env#" \
        -e "s/systemd-\(.*\)-work-report/systemd-\1-$WRP_NAME/" \
        "$WRP_SRC/deploy/quadlet/work-report.container" > "$QUADLET_DIR/$WRP_NAME.container"
}
