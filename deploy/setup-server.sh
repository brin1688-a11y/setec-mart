#!/usr/bin/env bash
#
# One-time provisioning for Setec Mart on a plain Ubuntu 24.04 server
# (AWS EC2, RackNerd, DigitalOcean, Oracle Cloud — all the same from here).
# Safe to run again — every step checks before it changes anything.
#
#   sudo DOMAIN=shop.example.com bash deploy/setup-server.sh
#
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/setec-mart}
DOMAIN=${DOMAIN:-_}
REPO=${REPO:-}

if [[ $EUID -ne 0 ]]; then
    echo "Run this with sudo." >&2
    exit 1
fi

say() { printf '\n\033[1;32m==>\033[0m %s\n' "$1"; }

# Which PHP the distribution carries, rather than a version hardcoded here:
# 24.04 ships 8.3, 26.04 ships 8.5, and pinning either one breaks on the other.
apt-get update -qq
PHP_VERSION=${PHP_VERSION:-$(
    apt-cache search --names-only '^php[0-9]+\.[0-9]+-fpm$' \
        | grep -oE 'php[0-9]+\.[0-9]+' | sed 's/php//' | sort -V | tail -1
)}

if [[ -z $PHP_VERSION ]]; then
    echo "No phpX.Y-fpm package found in apt. Is the universe repository enabled?" >&2
    exit 1
fi

# Laravel 12 needs 8.2 or newer.
if [[ $(printf '%s\n8.2\n' "$PHP_VERSION" | sort -V | head -1) != "8.2" ]]; then
    echo "This distribution offers PHP $PHP_VERSION; Laravel 12 needs 8.2+." >&2
    exit 1
fi

say "Using PHP $PHP_VERSION"

# ---------------------------------------------------------------- packages
# The distribution's own PHP satisfies Laravel 12's "php": "^8.2", so there is
# no third-party PPA to add — and none of them build reliably for ARM anyway.
say "Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y -qq \
    nginx git unzip curl supervisor \
    php${PHP_VERSION}-fpm php${PHP_VERSION}-cli \
    php${PHP_VERSION}-pgsql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml \
    php${PHP_VERSION}-curl php${PHP_VERSION}-bcmath php${PHP_VERSION}-zip \
    php${PHP_VERSION}-intl

if ! command -v composer >/dev/null; then
    say "Installing Composer"
    curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer
fi

# ------------------------------------------------------------------- ports
# On AWS the security group is the firewall and the INPUT chain is empty, so
# these rules change nothing. On Oracle the image ships an INPUT chain that
# rejects everything but SSH, and this is the step everyone misses.
#
# Inserted at the head of the chain: appending would land after Oracle's
# closing REJECT rule and never match.
say "Opening ports 80 and 443 in the local firewall"
if ! command -v netfilter-persistent >/dev/null; then
    apt-get install -y -qq iptables-persistent
fi
for port in 80 443; do
    if ! iptables -C INPUT -p tcp --dport "$port" -m state --state NEW -j ACCEPT 2>/dev/null; then
        iptables -I INPUT -p tcp --dport "$port" -m state --state NEW -j ACCEPT
    fi
done
netfilter-persistent save >/dev/null

# -------------------------------------------------------------------- swap
# t3.micro has 1GB of RAM. `composer install` resolving Laravel's dependency
# tree will be killed by the OOM reaper without somewhere to spill.
if [[ ! -f /swapfile ]] && (( $(free -m | awk '/^Mem:/{print $2}') < 2048 )); then
    say "Adding 2GB of swap"
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# --------------------------------------------------------------- app files
if [[ ! -d $APP_DIR ]]; then
    if [[ -z $REPO ]]; then
        echo "REPO is not set and $APP_DIR does not exist." >&2
        echo "Re-run with: sudo REPO=git@github.com:you/setec-mart.git bash $0" >&2
        exit 1
    fi
    say "Cloning $REPO"
    git clone "$REPO" "$APP_DIR"
fi

say "Setting ownership"
chown -R www-data:www-data "$APP_DIR"
# The web user needs to write only these two trees.
chmod -R ug+rwx "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# --------------------------------------------------------------- PHP limits
# A product can carry 10 gallery images at 4MB each, so the defaults (2MB
# upload / 8MB post) would reject a full gallery.
say "Raising PHP upload limits"
cat > /etc/php/${PHP_VERSION}/fpm/conf.d/99-setec-mart.ini <<'INI'
upload_max_filesize = 8M
post_max_size = 48M
max_file_uploads = 20
memory_limit = 256M
INI

# ------------------------------------------------------------------- nginx
say "Installing the nginx site"
sed -e "s|{{DOMAIN}}|${DOMAIN}|g" \
    -e "s|{{APP_DIR}}|${APP_DIR}|g" \
    -e "s|{{PHP_VERSION}}|${PHP_VERSION}|g" \
    "$APP_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/setec-mart

ln -sf /etc/nginx/sites-available/setec-mart /etc/nginx/sites-enabled/setec-mart
rm -f /etc/nginx/sites-enabled/default
nginx -t

# -------------------------------------------------------------- queue worker
say "Installing the queue worker"
sed -e "s|{{APP_DIR}}|${APP_DIR}|g" \
    "$APP_DIR/deploy/supervisor.conf" > /etc/supervisor/conf.d/setec-worker.conf

# --------------------------------------------------------------- scheduler
# cutluy:reconcile runs every five minutes off this one entry.
say "Installing the scheduler cron entry"
CRON="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
( crontab -u www-data -l 2>/dev/null | grep -Fv 'artisan schedule:run'; echo "$CRON" ) \
    | crontab -u www-data -

# ------------------------------------------------------------------ restart
say "Starting services"
systemctl enable --now nginx supervisor "php${PHP_VERSION}-fpm" >/dev/null
systemctl reload nginx
supervisorctl reread >/dev/null
supervisorctl update >/dev/null

cat <<DONE

Provisioned. Still to do by hand:

  1. Write $APP_DIR/.env       (see DEPLOY.md for the Supabase settings)
  2. php artisan key:generate  (as www-data)
  3. bash deploy/deploy.sh     (installs vendors, migrates, caches, restarts)
  4. certbot --nginx -d $DOMAIN
DONE
