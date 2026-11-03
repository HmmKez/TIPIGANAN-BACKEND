#!/usr/bin/env bash
# First-time setup of a new Ubuntu 24.04 server. Run once, as root, from the
# cloned backend folder, after both projects are on the server:
#
#   sudo DOMAIN=repo.example.com FRONTEND=/var/www/tipiganan/frontend bash deploy/provision.sh
#
# Optional: EMAIL=you@example.com turns on https with Let's Encrypt (the domain
# must already point at this server), and NO_MEILISEARCH=1 skips Meilisearch.
# It does not create the first Super Admin; see deploy/README.md.
set -euo pipefail

: "${DOMAIN:?Set DOMAIN, for example DOMAIN=repo.example.com}"
BACKEND="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND="${FRONTEND:-$(dirname "$BACKEND")/frontend}"
OWNER="${SUDO_USER:-$(stat -c %U "$BACKEND")}"
NODE_MAJOR=24
MEILISEARCH_VERSION=v1.49.0
PHP=8.3

step() { printf '\n==> %s\n' "$*"; }
as_owner() { sudo -u "$OWNER" -H "$@"; }
as_web() { sudo -u www-data -H "$@"; }

# Files in storage and bootstrap/cache must stay writable by both the web server
# and the person updating, or a file one of them made blocks the other.
fix_permissions() {
    chown -R "$OWNER":www-data "$BACKEND/storage" "$BACKEND/bootstrap/cache"
    find "$BACKEND/storage" "$BACKEND/bootstrap/cache" -type d -exec chmod 2775 {} +
    find "$BACKEND/storage" "$BACKEND/bootstrap/cache" -type f -exec chmod 664 {} +
}

[ "$(id -u)" -eq 0 ] || { echo "Run this with sudo." >&2; exit 1; }
[ -d "$FRONTEND/src" ] || { echo "The frontend project was not found at $FRONTEND. Set FRONTEND=..." >&2; exit 1; }
. /etc/os-release
[ "${VERSION_ID:-}" = "24.04" ] || echo "Warning: written for Ubuntu 24.04; this is ${PRETTY_NAME:-unknown}."

step "Installing software"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y curl ca-certificates gnupg unzip git rsync openssl \
    nginx mysql-server mysql-client redis-server qpdf composer \
    "php${PHP}-fpm" "php${PHP}-cli" "php${PHP}-mysql" "php${PHP}-mbstring" "php${PHP}-xml" \
    "php${PHP}-curl" "php${PHP}-gd" "php${PHP}-zip" "php${PHP}-bcmath" "php${PHP}-intl" "php${PHP}-opcache"

if ! command -v node >/dev/null || [ "$(node -v | cut -d. -f1 | tr -d v)" -lt "$NODE_MAJOR" ]; then
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
    apt-get install -y nodejs
fi

step "Creating .env"
secrets=""
if [ -e "$BACKEND/.env" ]; then
    echo "Keeping the existing .env. Its passwords must already match MySQL, Redis and Meilisearch."
else
    secrets="$(bash "$BACKEND/deploy/make-env.sh" "$DOMAIN")"
    DB_PASSWORD="$(printf '%s\n' "$secrets" | sed -n 's/^DB_PASSWORD=//p')"
    REDIS_PASSWORD="$(printf '%s\n' "$secrets" | sed -n 's/^REDIS_PASSWORD=//p')"
    MEILI_MASTER_KEY="$(printf '%s\n' "$secrets" | sed -n 's/^MEILI_MASTER_KEY=//p')"
    chown "$OWNER":www-data "$BACKEND/.env"
fi

if [ -n "$secrets" ]; then
    step "Setting up MySQL and Redis"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS tipiganan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'tipiganan'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'tipiganan'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON tipiganan.* TO 'tipiganan'@'localhost';
FLUSH PRIVILEGES;
SQL
    # Ubuntu's Redis already listens only on this server; this adds the password.
    sed -i '/^requirepass /d' /etc/redis/redis.conf
    echo "requirepass ${REDIS_PASSWORD}" >> /etc/redis/redis.conf
    systemctl restart redis-server
fi

step "Installing the backend"
chown -R "$OWNER":www-data "$BACKEND"
as_owner composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$BACKEND"
(cd "$BACKEND" && as_owner npm ci --omit=dev)
mkdir -p "$BACKEND/storage/app/backups" "$BACKEND/storage/app/ocr-cache" "$BACKEND/storage/app/scan-inbox"
fix_permissions

cd "$BACKEND"
if ! grep -q '^APP_KEY=base64' .env; then
    as_owner php artisan key:generate --force
fi
as_web php artisan migrate --force
as_web php artisan db:seed --force
# As the owner: www-data may not write to public/, and should not need to.
as_owner php artisan storage:link --force
fix_permissions

step "Building the frontend"
chown -R "$OWNER" "$FRONTEND"
printf 'VITE_API_BASE_URL=https://%s/api\n' "$DOMAIN" > "$FRONTEND/.env.production"
chown "$OWNER" "$FRONTEND/.env.production"
(cd "$FRONTEND" && as_owner npm ci && as_owner npm run build)

step "Configuring PHP and Nginx"
cp "$BACKEND/deploy/php/99-tipiganan.ini" "/etc/php/${PHP}/fpm/conf.d/99-tipiganan.ini"
cp "$BACKEND/deploy/php/99-tipiganan.ini" "/etc/php/${PHP}/cli/conf.d/99-tipiganan.ini"
cp "$BACKEND/deploy/php/tipiganan-pool.conf" "/etc/php/${PHP}/fpm/pool.d/zz-tipiganan.conf"
systemctl restart "php${PHP}-fpm"

fill() {
    sed -e "s#__DOMAIN__#${DOMAIN}#g" -e "s#__BACKEND__#${BACKEND}#g" -e "s#__FRONTEND__#${FRONTEND}#g" "$1"
}
fill "$BACKEND/deploy/nginx/tipiganan.conf" > /etc/nginx/sites-available/tipiganan
ln -sf /etc/nginx/sites-available/tipiganan /etc/nginx/sites-enabled/tipiganan
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

step "Starting the background processes"
fill "$BACKEND/deploy/systemd/tipiganan-worker.service" > /etc/systemd/system/tipiganan-worker.service
fill "$BACKEND/deploy/cron/tipiganan" > /etc/cron.d/tipiganan
chmod 644 /etc/cron.d/tipiganan
chmod +x "$BACKEND/deploy/backup-offsite.sh" "$BACKEND/deploy/update.sh" "$BACKEND/deploy/make-env.sh"
mkdir -p /etc/tipiganan
[ -e /etc/tipiganan/backup.env ] || printf '# Where backups are copied, for example backup@host:/backups/tipiganan\nBACKUP_TARGET=\n' > /etc/tipiganan/backup.env
chmod 600 /etc/tipiganan/backup.env

if [ -z "${NO_MEILISEARCH:-}" ]; then
    case "$(uname -m)" in
        x86_64) meili_arch=amd64 ;;
        aarch64) meili_arch=aarch64 ;;
        *) echo "Unsupported processor: $(uname -m)" >&2; exit 1 ;;
    esac
    if [ ! -x /usr/local/bin/meilisearch ]; then
        curl -fsSL -o /usr/local/bin/meilisearch \
            "https://github.com/meilisearch/meilisearch/releases/download/${MEILISEARCH_VERSION}/meilisearch-linux-${meili_arch}"
        chmod 755 /usr/local/bin/meilisearch
    fi
    id meilisearch >/dev/null 2>&1 || useradd --system --home /var/lib/meilisearch --shell /usr/sbin/nologin meilisearch
    mkdir -p /var/lib/meilisearch
    chown meilisearch:meilisearch /var/lib/meilisearch
    if [ -n "${MEILI_MASTER_KEY:-}" ]; then
        printf 'MEILI_MASTER_KEY=%s\n' "$MEILI_MASTER_KEY" > /etc/tipiganan/meilisearch.env
        chmod 600 /etc/tipiganan/meilisearch.env
    fi
    cp "$BACKEND/deploy/systemd/tipiganan-meilisearch.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now tipiganan-meilisearch
    sleep 3
    as_web php artisan scout:sync-index-settings || echo "Search settings not synced yet; update.sh tries again."
else
    sed -i 's/^SCOUT_DRIVER=.*/SCOUT_DRIVER=database/' .env
fi

systemctl daemon-reload
systemctl enable --now tipiganan-worker

if [ -n "${EMAIL:-}" ]; then
    step "Turning on https"
    apt-get install -y certbot python3-certbot-nginx
    certbot --nginx --non-interactive --agree-tos --redirect -m "$EMAIL" -d "$DOMAIN"
else
    echo
    echo "https is NOT on yet. The system expects it. When the domain points here, run:"
    echo "  sudo apt-get install -y certbot python3-certbot-nginx"
    echo "  sudo certbot --nginx --redirect -d ${DOMAIN}"
fi

step "Caching the configuration"
as_web php artisan config:cache
as_web php artisan route:cache
as_web php artisan event:cache
as_web php artisan view:cache
systemctl reload "php${PHP}-fpm"
systemctl restart tipiganan-worker

fix_permissions

step "Checking the result"
echo "A FAIL for no Super Admin is expected here; the next step creates one."
as_web php artisan deploy:check || true

cat <<NEXT

Next:
  1. cd ${BACKEND}
  2. sudo -u www-data php artisan accounts:create-super-admin
  3. Sign in at https://${DOMAIN} and import the school list on School Records.
  4. Set BACKUP_TARGET in /etc/tipiganan/backup.env (see deploy/README.md).
NEXT
