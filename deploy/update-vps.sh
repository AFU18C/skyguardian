#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="${SKYGUARDIAN_PROJECT_DIR:-/var/www/SkyGuardianUa}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || true)}"

if [[ ! -d "$PROJECT_DIR/.git" ]]; then
  echo "SkyGuardian repository not found: $PROJECT_DIR" >&2
  exit 1
fi

cd "$PROJECT_DIR"

git fetch --prune origin
git reset --hard origin/main

mkdir -p \
  storage/backups \
  storage/telegram-news-sessions \
  storage/telegram-sessions

if [[ -n "$COMPOSER_BIN" ]]; then
  "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
elif [[ ! -f vendor/autoload.php ]]; then
  echo "Composer is unavailable and vendor/autoload.php is missing." >&2
  exit 1
fi

chown -R www-data:www-data storage
chmod -R u+rwX,g+rwX,o-rwx storage

install -m 0644 deploy/skyguardian-data-news.service /etc/systemd/system/skyguardian-data-news.service
install -m 0644 deploy/skyguardian-data-alerts.service /etc/systemd/system/skyguardian-data-alerts.service

systemctl daemon-reload
systemctl restart skyguardian-data-news.service skyguardian-data-alerts.service

"$PHP_BIN" -l public/worker-status.php >/dev/null
"$PHP_BIN" -l public/worker-notifications.php >/dev/null
"$PHP_BIN" -l src/Worker/WorkerStatusService.php >/dev/null

systemctl is-active --quiet skyguardian-data-news.service
systemctl is-active --quiet skyguardian-data-alerts.service

echo "SkyGuardian update completed successfully."
