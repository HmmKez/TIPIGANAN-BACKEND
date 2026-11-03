#!/usr/bin/env bash
# Installs the newest code on a server that is already set up. Run from the
# backend folder after pushing:
#
#   sudo FRONTEND=/var/www/tipiganan/frontend bash deploy/update.sh
#
# People see a short maintenance response during the update. If a step fails, the
# script stops and the site stays in maintenance; fix it, then run this again.
set -euo pipefail

BACKEND="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND="${FRONTEND:-$(dirname "$BACKEND")/frontend}"
OWNER="${SUDO_USER:-$(stat -c %U "$BACKEND")}"
BRANCH="${BRANCH:-main}"

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
cd "$BACKEND"

echo "==> Backing up the database first"
as_web php artisan backup:database

echo "==> Getting the newest code (${BRANCH})"
as_owner git -C "$BACKEND" pull --ff-only origin "$BRANCH"
as_owner git -C "$FRONTEND" pull --ff-only origin "$BRANCH"

as_web php artisan down --retry=30

echo "==> Installing dependencies"
as_owner composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$BACKEND"
as_owner npm ci --omit=dev

fix_permissions

echo "==> Updating the database and caches"
as_web php artisan migrate --force
as_web php artisan config:cache
as_web php artisan route:cache
as_web php artisan event:cache
as_web php artisan view:cache
if grep -q '^SCOUT_DRIVER=meilisearch' .env; then
    as_web php artisan scout:sync-index-settings || echo "Search settings not synced; search still works."
fi

echo "==> Building the frontend"
(cd "$FRONTEND" && as_owner npm ci && as_owner npm run build)

echo "==> Restarting"
# PHP keeps old code in memory until reloaded, and the worker until it restarts.
systemctl reload php8.3-fpm
as_web php artisan queue:restart
as_web php artisan up

echo "==> Checking the result"
as_web php artisan deploy:check
