#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/skyguardian}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

cd "$APP_DIR"
$COMPOSER_BIN install --no-dev --prefer-dist --no-interaction --optimize-autoloader

find src public/v1 bin -type f -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l

mkdir -p storage/v1
chown -R www-data:www-data storage
find storage -type d -exec chmod 770 {} \;
find storage -type f -exec chmod 600 {} \;

$PHP_BIN bin/migrate-v1-storage.php

install -m 0644 deploy/systemd/skyguardian-news-v1.service /etc/systemd/system/
install -m 0644 deploy/systemd/skyguardian-alerts-v1.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now skyguardian-news-v1.service skyguardian-alerts-v1.service
systemctl restart skyguardian-news-v1.service skyguardian-alerts-v1.service
systemctl is-active --quiet skyguardian-news-v1.service
systemctl is-active --quiet skyguardian-alerts-v1.service

echo 'SkyGuardian v1 deployed successfully.'
