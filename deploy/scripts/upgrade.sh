#!/usr/bin/env bash
# Upgrade to the source tree's version: build -> switch :current -> restart ->
# health check; on failure the previous image is restored and restarted.
#   deploy/scripts/upgrade.sh            (source = this repository, version = git HEAD)
# shellcheck source=deploy/scripts/lib.sh
source "$(dirname "$0")/lib.sh"

ver=${1:-$(src_version)}
build_image "$ver"
ensure_collab_secret
build_collab_image

prev=$(podman image inspect "$WRP_IMAGE:current" --format '{{.Id}}' 2>/dev/null || true)
if [ -n "$prev" ] && [ "$prev" = "$(podman image inspect "$WRP_IMAGE:$ver" --format '{{.Id}}')" ]; then
    log "$ver is already current"
    if ! systemctl --user is-active -q "$WRP_NAME.service"; then
        render_quadlet
        render_backup_units
        render_collab_units
        systemctl --user daemon-reload
        systemctl --user restart "$WRP_NAME-collab.service"
        systemctl --user start "$WRP_NAME.service"
        wait_healthy 120 || die "$WRP_NAME did not become healthy"
    fi
    exit 0
fi

[ -n "$prev" ] && podman tag "$prev" "$WRP_IMAGE:previous"
podman tag "$WRP_IMAGE:$ver" "$WRP_IMAGE:current"
render_quadlet
render_backup_units
render_collab_units
systemctl --user daemon-reload
systemctl --user restart "$WRP_NAME-collab.service"
systemctl --user restart "$WRP_NAME.service"

if wait_healthy 120; then
    log "upgraded to $ver"
    podman images "$WRP_IMAGE" --format '{{.Tag}} {{.CreatedAt}}' | grep -vE '^(current|previous) ' |
        sort -k2 -r | awk 'NR>3 {print $1}' |
        while read -r tag; do podman rmi "$WRP_IMAGE:$tag" >/dev/null 2>&1 || true; done
    exit 0
fi

log "upgrade to $ver failed health check; rolling back"
journalctl --user -u "$WRP_NAME" -n 60 --no-pager || true
if [ -n "$prev" ]; then
    podman tag "$prev" "$WRP_IMAGE:current"
    systemctl --user restart "$WRP_NAME.service"
    wait_healthy 90 && log "rolled back" || log "rollback also unhealthy"
fi
exit 1
