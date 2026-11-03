#!/usr/bin/env bash
# Copies the database backups and every uploaded file to another machine, because
# backups on the same disk are lost along with it. Cron runs this daily once
# /etc/tipiganan/backup.env sets BACKUP_TARGET, for example:
#
#   BACKUP_TARGET=backup@192.0.2.10:/backups/tipiganan
#
# Uses rsync over SSH with the root user's key. Files deleted here are kept there,
# so an accidental delete cannot wipe the copy.
set -euo pipefail

BACKEND="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="/etc/tipiganan/backup.env"

BACKUP_TARGET=""
[ -r "$CONFIG" ] && . "$CONFIG"

if [ -z "$BACKUP_TARGET" ]; then
    echo "$(date '+%F %T') SKIPPED: no BACKUP_TARGET in ${CONFIG}. Backups are only on this server."
    exit 0
fi

echo "$(date '+%F %T') Copying to ${BACKUP_TARGET}"
rsync -a --partial --timeout=600 \
    "${BACKEND}/storage/app/backups" \
    "${BACKEND}/storage/app/private" \
    "${BACKEND}/storage/app/public" \
    "${BACKUP_TARGET}/"
echo "$(date '+%F %T') Done"
