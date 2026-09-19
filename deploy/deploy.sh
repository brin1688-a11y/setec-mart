#!/usr/bin/env bash
#
# Ship the current main branch. Run it on the server, from the app directory:
#
#   cd /var/www/setec-mart && sudo -u www-data bash deploy/deploy.sh
#
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/setec-mart}
BRANCH=${BRANCH:-main}

# Whatever setup-server.sh installed — 8.3 on Ubuntu 24.04, 8.5 on 26.04.
PHP_VERSION=${PHP_VERSION:-$(ls /etc/php 2>/dev/null | sort -V | tail -1)}

cd "$APP_DIR"

say() { printf '\n\033[1;32m==>\033[0m %s\n' "$1"; }

# Whatever happens below, the shop comes back up.
trap 'php artisan up >/dev/null 2>&1 || true' EXIT

say "Maintenance mode"
php artisan down --retry=15 >/dev/null 2>&1 || true

say "Fetching $BRANCH"
git fetch --quiet origin "$BRANCH"
git reset --hard "origin/$BRANCH"

say "Installing dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --quiet

say "Running migrations"
php artisan migrate --force

say "Caching config, routes and views"
# config:clear first: a stale cache would be read while we rebuild.
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

say "Linking storage"
php artisan storage:link >/dev/null 2>&1 || true

say "Restarting the queue worker"
# The worker holds the old code in memory until it is told to finish.
php artisan queue:restart
sudo supervisorctl restart setec-worker:* >/dev/null

say "Reloading PHP"
sudo systemctl reload "php${PHP_VERSION}-fpm"

say "Live"
php artisan up
trap - EXIT

curl -fsS -o /dev/null -w 'health check: %{http_code}\n' http://127.0.0.1/up || {
    echo "Health check failed — check storage/logs/laravel.log" >&2
    exit 1
}
