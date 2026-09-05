#!/bin/bash
# Entrypoint for every HQ PHP container (app, horizon, scheduler).
#
# Waits for Postgres, republishes public assets for the nginx sidecar, warms the
# framework caches, and only runs migrations when RUN_MIGRATIONS=1 is set for
# THAT container (the compose file sets it on `app` only, so horizon and the
# scheduler never race the migration).
set -euo pipefail

cd /var/www/html

log() { echo "[entrypoint $(date -u +%FT%TZ)] $*"; }

: "${DB_HOST:=db}"
: "${DB_PORT:=5432}"

log "waiting for postgres at ${DB_HOST}:${DB_PORT}"
for i in $(seq 1 60); do
    if php -r 'try { new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 2]); exit(0); } catch (Throwable $e) { exit(1); }'; then
        break
    fi
    sleep 2
    if [ "$i" -eq 60 ]; then log "postgres not reachable after 120s"; exit 1; fi
done
log "postgres reachable"

# The `web` container mounts the same `public` volume; republish so a new image's
# built assets are what nginx serves, not the previous release's.
if [ -d /var/www/html/public-volume ]; then
    rsync -a --delete /var/www/html/public/ /var/www/html/public-volume/ 2>/dev/null || cp -a /var/www/html/public/. /var/www/html/public-volume/
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    log "running migrations"
    php artisan migrate --force --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

log "starting: $*"
exec "$@"
