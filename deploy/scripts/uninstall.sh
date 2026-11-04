#!/usr/bin/env bash
# Stop and remove the service. The database and the env file are kept.
#   deploy/scripts/uninstall.sh [--purge]
source "$(dirname "$0")/lib.sh"

systemctl --user stop "$WRP_NAME.service" 2>/dev/null || true
podman rm -f "$WRP_NAME" >/dev/null 2>&1 || true
rm -f "$QUADLET_DIR/$WRP_NAME.container"

# The mirror timer goes with the service, but the dump it already pushed stays on
# the AI host on purpose: removing a service must never delete the last copy.
systemctl --user disable --now "$WRP_NAME-backup-offsite.timer" 2>/dev/null || true
rm -f "$HOME/.config/systemd/user/$WRP_NAME-backup-offsite.service" \
      "$HOME/.config/systemd/user/$WRP_NAME-backup-offsite.timer"

systemctl --user daemon-reload
log "service removed; the offsite copy on the AI host is kept"

if [ "${1:-}" = "--purge" ]; then
    podman rmi "$WRP_IMAGE:current" "$WRP_IMAGE:previous" >/dev/null 2>&1 || true
    log "images removed (env file and database kept on purpose)"
fi
