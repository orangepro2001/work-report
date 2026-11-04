#!/usr/bin/env bash
# First-time install: build the image, write the env file, install the Quadlet
# unit and start the service.
#   deploy/scripts/install.sh [--env-file PATH]
# Test instance: WRP_NAME=work-report-test WRP_PORT=3561 deploy/scripts/install.sh
# shellcheck source=deploy/scripts/lib.sh
source "$(dirname "$0")/lib.sh"

ENV_SRC=""
while [ $# -gt 0 ]; do
    case $1 in
        --env-file) ENV_SRC=$2; shift 2 ;;
        *) die "unknown argument $1" ;;
    esac
done

command -v podman >/dev/null || die "podman not found"
mkdir -p "$CONF_DIR"
chmod 700 "$CONF_DIR"

if [ ! -f "$CONF_DIR/env" ]; then
    cp "${ENV_SRC:-$WRP_SRC/deploy/env/work-report.env.example}" "$CONF_DIR/env"
    chmod 600 "$CONF_DIR/env"
    die "wrote $CONF_DIR/env — fill in APP_KEY and DB_PASSWORD, then re-run install.sh"
fi

grep -q '^APP_KEY=.\+' "$CONF_DIR/env" || die "APP_KEY is empty in $CONF_DIR/env"
grep -q '^DB_PASSWORD=.\+' "$CONF_DIR/env" || die "DB_PASSWORD is empty in $CONF_DIR/env"

ver=$(src_version)
build_image "$ver"
podman tag "$WRP_IMAGE:$ver" "$WRP_IMAGE:current"

render_quadlet
systemctl --user daemon-reload
systemctl --user start "$WRP_NAME.service"
wait_healthy 120 || { journalctl --user -u "$WRP_NAME" -n 60 --no-pager; die "service did not become healthy"; }

log "installed $ver"
log "expose it with:  tailscale serve --bg --set-path /report http://127.0.0.1:$WRP_PORT"
