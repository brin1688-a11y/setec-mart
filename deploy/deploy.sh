#!/usr/bin/env bash
#
# Ship the current main branch. Run it on the server as root:
#
#   cd /var/www/setec-mart && sudo bash deploy/deploy.sh
#
# Root is needed to restart the worker and reload PHP-FPM. Everything that
# touches the application — git, composer, artisan — drops to www-data, so no
# file ends up owned by root where the web user then cannot write it.
#
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/setec-mart}
BRANCH=${BRANCH:-main}

# Whatever setup-server.sh installed — 8.3 on Ubuntu 24.04, 8.5 on 26.04.
PHP_VERSION=${PHP_VERSION:-$(ls /etc/php 2>/dev/null | sort -V | tail -1)}

if [[ $EUID -ne 0 ]]; then
    echo "Run this with sudo: sudo bash deploy/deploy.sh" >&2
    exit 1
fi

cd "$APP_DIR"

say() { printf '\n\033[1;32m==>\033[0m %s\n' "$1"; }
as_www() { sudo -u www-data -H "$@"; }

# Whatever happens below, the shop comes back up.
trap 'as_www php artisan up >/dev/null 2>&1 || true' EXIT

say "Maintenance mode"
as_www php artisan down --retry=15 >/dev/null 2>&1 || true

say "Fetching $BRANCH"
as_www git fetch --quiet origin "$BRANCH"
as_www git reset --hard "origin/$BRANCH"

say "Installing dependencies"
as_www composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --quiet

say "Running migrations"
as_www php artisan migrate --force

say "Caching config, routes and views"
# config:clear first: a stale cache would be read while we rebuild.
as_www php artisan config:clear
as_www php artisan config:cache
as_www php artisan route:cache
as_www php artisan view:cache
as_www php artisan event:cache

say "Linking storage"
as_www php artisan storage:link >/dev/null 2>&1 || true

say "Restarting the queue worker"
# The worker holds the old code in memory until it is told to finish.
as_www php artisan queue:restart
supervisorctl restart setec-worker:* >/dev/null

say "Reloading PHP"
systemctl reload "php${PHP_VERSION}-fpm"

say "Live"
as_www php artisan up
trap - EXIT

curl -fsS -o /dev/null -w 'health check: %{http_code}\n' http://127.0.0.1/up || {
    echo "Health check failed — check storage/logs/laravel.log" >&2
    exit 1
}
