#!/usr/bin/env bash
# Writes the backend's .env from .env.production.example, with new random
# passwords. Refuses to replace an existing .env, because that would change the
# passwords and APP_KEY of a running system.
#
#   deploy/make-env.sh repo.example.com
#
# It prints the database, Redis and Meilisearch passwords it chose as
# KEY=value lines, so provision.sh can set the same ones in those services.
set -euo pipefail

domain="${1:?Usage: make-env.sh DOMAIN}"
backend="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${backend}/.env"

if [ -e "$env_file" ]; then
    echo "make-env: ${env_file} already exists; leaving it alone." >&2
    exit 1
fi

db_password="$(openssl rand -hex 24)"
redis_password="$(openssl rand -hex 24)"
meili_key="$(openssl rand -hex 32)"

# Random hex and a checked domain contain nothing sed treats specially.
if ! printf '%s' "$domain" | grep -Eq '^[A-Za-z0-9.-]+$'; then
    echo "make-env: '${domain}' does not look like a domain." >&2
    exit 1
fi

sed -e "s/CHANGE_ME_API_DOMAIN/${domain}/g" \
    -e "s/CHANGE_ME_FRONTEND_DOMAIN/${domain}/g" \
    -e "s/CHANGE_ME_DOMAIN/${domain}/g" \
    -e "s/CHANGE_ME_DB_USER/tipiganan/g" \
    -e "s/CHANGE_ME_STRONG_DB_PASSWORD/${db_password}/g" \
    -e "s/CHANGE_ME_REDIS_PASSWORD/${redis_password}/g" \
    -e "s/CHANGE_ME_MEILISEARCH_MASTER_KEY/${meili_key}/g" \
    "${backend}/.env.production.example" > "$env_file"
chmod 640 "$env_file"

if grep -q 'CHANGE_ME' "$env_file"; then
    echo "make-env: a CHANGE_ME value was left in ${env_file}." >&2
    exit 1
fi

echo "DB_PASSWORD=${db_password}"
echo "REDIS_PASSWORD=${redis_password}"
echo "MEILI_MASTER_KEY=${meili_key}"
