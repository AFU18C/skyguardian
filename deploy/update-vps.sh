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
  "$COMPOSER_BIN" validate --no-check-lock
  "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  "$COMPOSER_BIN" dump-autoload --no-dev --optimize --strict-psr
  "$COMPOSER_BIN" audit --no-interaction
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

while IFS= read -r -d '' php_file; do
  "$PHP_BIN" -l "$php_file" >/dev/null
done < <(find public src bin -type f -name '*.php' -print0)

systemctl is-active --quiet skyguardian-data-news.service
systemctl is-active --quiet skyguardian-data-alerts.service

curl --fail --silent --show-error \
  -H 'Host: skyguardian.pp.ua' \
  http://127.0.0.1/worker-status.php \
  --output /dev/null || {
    status=$(curl --silent --output /dev/null --write-out '%{http_code}' -H 'Host: skyguardian.pp.ua' http://127.0.0.1/worker-status.php || true)
    [[ "$status" == "401" ]] || { echo "Worker status endpoint health check failed: HTTP $status" >&2; exit 1; }
  }

echo "SkyGuardian update completed successfully."
