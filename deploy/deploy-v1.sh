#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/skyguardian}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/skyguardian}"

cd "$APP_DIR"
mkdir -p "$BACKUP_DIR"
if [ -d storage ]; then
  tar -czf "$BACKUP_DIR/storage-$(date +%Y%m%d-%H%M%S).tar.gz" storage
fi

$COMPOSER_BIN install --no-dev --prefer-dist --no-interaction --optimize-autoloader
find src public/v1 bin tests -type f -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l
$PHP_BIN tests/smoke.php

mkdir -p storage/v1
chown -R www-data:www-data storage
find storage -type d -exec chmod 770 {} \;
find storage -type f -exec chmod 600 {} \;
$PHP_BIN bin/migrate-v1-storage.php

install -m 0644 deploy/systemd/skyguardian-news-v1.service /etc/systemd/system/
install -m 0644 deploy/systemd/skyguardian-alerts-v1.service /etc/systemd/system/
install -m 0644 deploy/systemd/skyguardian-bot-polling-v1.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable skyguardian-news-v1.service skyguardian-alerts-v1.service skyguardian-bot-polling-v1.service
systemctl restart skyguardian-news-v1.service skyguardian-alerts-v1.service skyguardian-bot-polling-v1.service
systemctl is-active --quiet skyguardian-news-v1.service
systemctl is-active --quiet skyguardian-alerts-v1.service
systemctl is-active --quiet skyguardian-bot-polling-v1.service
curl --fail --silent --show-error http://127.0.0.1/v1/index.php >/dev/null

echo 'SkyGuardian v1 deployed successfully.'
