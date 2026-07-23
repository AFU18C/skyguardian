#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="${SKYGUARDIAN_PROJECT_DIR:-/var/www/SkyGuardianUa}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || true)}"
RUNTIME_BACKUP_DIR="${SKYGUARDIAN_RUNTIME_BACKUP_DIR:-/var/backups/skyguardian-runtime}"
ALLOW_UNINITIALIZED="${SKYGUARDIAN_ALLOW_UNINITIALIZED:-0}"

if [[ ! -d "$PROJECT_DIR/.git" ]]; then
  echo "SkyGuardian repository not found: $PROJECT_DIR" >&2
  exit 1
fi

cd "$PROJECT_DIR"

mkdir -p storage "$RUNTIME_BACKUP_DIR"
chmod 0700 "$RUNTIME_BACKUP_DIR"

if [[ ! -f storage/admin.json && "$ALLOW_UNINITIALIZED" != "1" ]]; then
  echo "Deployment stopped: storage/admin.json is missing." >&2
  echo "Create the administrator with: php artisan admin:create" >&2
  echo "For a first-time installation only, rerun with SKYGUARDIAN_ALLOW_UNINITIALIZED=1." >&2
  exit 1
fi

snapshot="$RUNTIME_BACKUP_DIR/runtime-$(date -u +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$snapshot" -C "$PROJECT_DIR" storage
chmod 0600 "$snapshot"
find "$RUNTIME_BACKUP_DIR" -type f -name 'runtime-*.tar.gz' -mtime +14 -delete

git fetch --prune origin
git reset --hard origin/main

mkdir -p \
  storage/backups \
  storage/telegram-news-sessions \
  storage/telegram-sessions \
  storage/madeline-runtime

if [[ -n "$COMPOSER_BIN" ]]; then
  "$COMPOSER_BIN" validate --no-check-lock
  "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  "$COMPOSER_BIN" dump-autoload --no-dev --optimize --strict-psr
  "$COMPOSER_BIN" audit --no-interaction
elif [[ ! -f vendor/autoload.php ]]; then
  echo "Composer is unavailable and vendor/autoload.php is missing." >&2
  exit 1
fi

# Runtime state belongs to PHP-FPM and workers, never to root.
chown -R www-data:www-data storage
chmod -R u+rwX,g+rwX,o-rwx storage
find storage -type f -exec chmod 0660 {} +

# Remove the obsolete web-root log and prepare the only allowed MadelineProto log.
rm -f public/MadelineProto.log
install -o www-data -g www-data -m 0660 /dev/null storage/madeline-runtime/MadelineProto.log

# Verify permissions as the actual PHP-FPM user, not as root.
runuser -u www-data -- test -w storage/madeline-runtime
runuser -u www-data -- test -w storage/telegram-sessions
runuser -u www-data -- sh -c 'probe="storage/madeline-runtime/.write-probe-$$"; : > "$probe" && rm -f "$probe"'
runuser -u www-data -- sh -c 'probe="storage/telegram-sessions/.write-probe-$$"; : > "$probe" && rm -f "$probe"'

install -m 0644 deploy/skyguardian-data-news.service /etc/systemd/system/skyguardian-data-news.service
install -m 0644 deploy/skyguardian-data-alerts.service /etc/systemd/system/skyguardian-data-alerts.service

systemctl daemon-reload
systemctl restart skyguardian-data-news.service skyguardian-data-alerts.service

while IFS= read -r -d '' php_file; do
  "$PHP_BIN" -l "$php_file" >/dev/null
done < <(find public src bin -type f -name '*.php' -print0)

systemctl is-active --quiet skyguardian-data-news.service
systemctl is-active --quiet skyguardian-data-alerts.service

if [[ "$ALLOW_UNINITIALIZED" == "1" ]]; then
  "$PHP_BIN" bin/runtime-audit.php
else
  "$PHP_BIN" bin/runtime-audit.php --production
fi

curl --fail --silent --show-error \
  -H 'Host: skyguardian.pp.ua' \
  http://127.0.0.1/worker-status.php \
  --output /dev/null || {
    status=$(curl --silent --output /dev/null --write-out '%{http_code}' -H 'Host: skyguardian.pp.ua' http://127.0.0.1/worker-status.php || true)
    [[ "$status" == "401" ]] || { echo "Worker status endpoint health check failed: HTTP $status" >&2; exit 1; }
  }

echo "SkyGuardian update completed successfully. Runtime snapshot: $snapshot"
