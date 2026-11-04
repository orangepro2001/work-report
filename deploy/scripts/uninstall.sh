#!/usr/bin/env bash
# Stop and remove the service. The database and the env file are kept.
#   deploy/scripts/uninstall.sh [--purge]
source "$(dirname "$0")/lib.sh"

systemctl --user stop "$WRP_NAME.service" 2>/dev/null || true
podman rm -f "$WRP_NAME" >/dev/null 2>&1 || true
rm -f "$QUADLET_DIR/$WRP_NAME.container"
systemctl --user daemon-reload
log "service removed"

if [ "${1:-}" = "--purge" ]; then
    podman rmi "$WRP_IMAGE:current" "$WRP_IMAGE:previous" >/dev/null 2>&1 || true
    log "images removed (env file and database kept on purpose)"
fi
