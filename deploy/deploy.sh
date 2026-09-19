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

# This script pulls, and the pull can rewrite this very file while bash is
# still reading it — bash reads a script in chunks and would carry on at the
# old byte offset in the new file. Run from a copy so a deploy always finishes
# with the version it started with; changes to this file take effect next time.
if [[ ${DEPLOY_FROM_COPY:-0} != 1 ]]; then
    SELF_COPY=$(mktemp)
    cp "$0" "$SELF_COPY"
    trap 'rm -f "$SELF_COPY"' EXIT
    DEPLOY_FROM_COPY=1 bash "$SELF_COPY" "$@"
    exit $?
fi

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

# Checked over the loopback so it does not depend on DNS, but with the site's
# own Host header: once server_name names the domain, an unmatched host falls
# through to certbot's redirect block and answers 404.
HOSTNAME_FOR_CHECK=$(
    grep -m1 '^APP_URL=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' | sed -E 's#^https?://##; s#/.*##'
)

HOST=${HOSTNAME_FOR_CHECK:-localhost}

# Pinned to the loopback so the check never depends on DNS, but addressed by
# the site's own name so nginx matches the right server block and the
# certificate validates. -L follows the redirect up to HTTPS.
curl -fsS -o /dev/null -L \
     --resolve "${HOST}:80:127.0.0.1" --resolve "${HOST}:443:127.0.0.1" \
     -w 'health check: %{http_code}\n' "http://${HOST}/up" || {
    echo "Health check failed — check storage/logs/laravel.log" >&2
    exit 1
}
