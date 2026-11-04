#!/bin/sh
# work-report container entrypoint.
#
# Runs the idempotent boot chores (migrations, seed, caches) and hands over to
# supervisord (php-fpm + nginx). Restarting the container is always safe.
set -eu

log() { printf '%s entrypoint: %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >&2; }

cd /app

# storage/ and bootstrap/cache are tmpfs mounts: the rootfs is read-only and
# Laravel writes compiled config, routes and views at boot.
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/public \
         bootstrap/cache /tmp/nginx

if [ -z "${APP_KEY:-}" ]; then
    log "APP_KEY is empty: generate one with 'php artisan key:generate --show'."
    exit 1
fi

log "discovering packages"
php artisan package:discover --ansi >/dev/null

# pg_trgm backs the JQL-lite search indexes; creating it is idempotent. The role may
# lack the privilege on a managed cluster, hence the "|| true".
php artisan db:statement "CREATE EXTENSION IF NOT EXISTS pg_trgm" >/dev/null 2>&1 || true

log "running migrations"
php artisan migrate --force --no-interaction

# First boot convenience: the team's existing workbook contents. Idempotent because
# every seeder is an upsert, and skippable with WR_SKIP_SEED=1.
if [ "${WR_SKIP_SEED:-0}" != "1" ]; then
    log "seeding reference data"
    php artisan db:seed --force --no-interaction >/dev/null
fi

log "caching configuration, routes and views"
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan view:cache --no-interaction >/dev/null

case "${1:-serve}" in
    serve)
        log "starting php-fpm and nginx on ${WR_LISTEN:-0.0.0.0:3560}"
        exec supervisord -c /etc/supervisor/supervisord.conf
        ;;
    migrate)
        exec php artisan migrate --force
        ;;
    artisan)
        shift
        exec php artisan "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
