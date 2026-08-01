#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/var/www/skyguardian"
WORKSPACE="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
CURRENT_SHA="${GITHUB_SHA:-$(git -C "$WORKSPACE" rev-parse HEAD)}"
PREVIOUS_SHA="${BEFORE_SHA:-}"

sudo mkdir -p "$APP_DIR"

# Runtime state must never be deleted by rsync. This includes uploaded CMS
# media, Laravel sessions/cache/logs and the public storage symlink.
sudo rsync -a --delete \
    --exclude=".git" \
    --exclude=".github" \
    --exclude=".env" \
    --exclude=".venv" \
    --exclude="node_modules" \
    --exclude="vendor" \
    --exclude="storage" \
    --exclude="public/storage" \
    "$WORKSPACE/" "$APP_DIR/"

cd "$APP_DIR"

sudo mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs/archive \
    bootstrap/cache

sudo chown -R github-runner:www-data "$APP_DIR"
sudo chmod -R ug+rwX storage bootstrap/cache
sudo touch storage/logs/laravel.log
sudo chown github-runner:www-data storage/logs/laravel.log
sudo chmod 664 storage/logs/laravel.log

composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

if [ ! -d .venv ]; then
    python3 -m venv .venv
fi
.venv/bin/pip install --disable-pip-version-check -r telethon/requirements.txt

npm ci --no-audit --no-fund
npm run build

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    php artisan key:generate --force
fi

upsert_env() {
    local key="$1"
    local value="$2"

    if grep -qE "^${key}=" .env; then
        sed -i "s#^${key}=.*#${key}=${value}#" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

upsert_env LOG_CHANNEL stack
upsert_env LOG_STACK daily
upsert_env LOG_DAILY_DAYS 14
upsert_env LOG_LEVEL info
upsert_env SESSION_SECURE_COOKIE true
upsert_env SESSION_HTTP_ONLY true
upsert_env SESSION_SAME_SITE lax

# sed -i recreates the file with the runner's primary group. Restore the
# production group so PHP-FPM and all www-data services read the real config.
sudo chown github-runner:www-data .env
sudo chmod 640 .env
sudo -u www-data test -r .env

if [ -f storage/logs/laravel.log ] \
    && [ "$(stat -c '%s' storage/logs/laravel.log)" -gt 10485760 ]; then
    ARCHIVE="storage/logs/archive/laravel-before-daily-$(date -u +%Y%m%dT%H%M%SZ).log.gz"
    sudo gzip -c storage/logs/laravel.log > "$ARCHIVE"
    sudo truncate -s 0 storage/logs/laravel.log
fi

if [ -e public/storage ] && [ ! -L public/storage ]; then
    rm -rf public/storage
fi
if [ ! -L public/storage ]; then
    php artisan storage:link
fi

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan schedule:list --no-ansi >/dev/null

sudo cp deploy/systemd/skyguardian-telethon.service /etc/systemd/system/skyguardian-telethon.service
sudo cp deploy/systemd/skyguardian-scheduler.service /etc/systemd/system/skyguardian-scheduler.service
sudo cp deploy/systemd/skyguardian-group-channel-telethon.service /etc/systemd/system/skyguardian-group-channel-telethon.service
sudo cp deploy/systemd/skyguardian-group-channel-delete.service /etc/systemd/system/skyguardian-group-channel-delete.service
sudo cp deploy/systemd/skyguardian-backup.service /etc/systemd/system/skyguardian-backup.service
sudo cp deploy/systemd/skyguardian-backup.timer /etc/systemd/system/skyguardian-backup.timer
sudo cp deploy/backup/skyguardian-full-backup.sh /usr/local/sbin/skyguardian-full-backup
sudo chmod 750 /usr/local/sbin/skyguardian-full-backup

sudo mkdir -p /etc/skyguardian
if [ ! -f /etc/skyguardian/backup.key ]; then
    sudo sh -c 'umask 077; openssl rand -base64 48 > /etc/skyguardian/backup.key'
fi
if [ ! -f /etc/skyguardian/backup.env ]; then
    printf '%s\n' \
        '# Optional encrypted offsite backup using rclone.' \
        '# Example: RCLONE_REMOTE=remote:skyguardian-backups' \
        'RCLONE_REMOTE=' \
        'BACKUP_ENCRYPTION_PASSWORD_FILE=/etc/skyguardian/backup.key' \
        | sudo tee /etc/skyguardian/backup.env >/dev/null
    sudo chmod 600 /etc/skyguardian/backup.env
fi

sudo systemctl daemon-reload
sudo systemctl enable \
    skyguardian-telethon.service \
    skyguardian-scheduler.service \
    skyguardian-group-channel-telethon.service \
    skyguardian-group-channel-delete.service \
    skyguardian-backup.timer

CORE_RESTART_REQUIRED=1
if [ -n "$PREVIOUS_SHA" ] \
    && [ "$PREVIOUS_SHA" != "0000000000000000000000000000000000000000" ] \
    && git -C "$WORKSPACE" cat-file -e "$PREVIOUS_SHA^{commit}" 2>/dev/null; then
    CHANGED_FILES="$(git -C "$WORKSPACE" diff --name-only "$PREVIOUS_SHA" "$CURRENT_SHA")"
    if [ -n "$CHANGED_FILES" ] \
        && ! printf '%s\n' "$CHANGED_FILES" \
            | grep -Ev '^(database/migrations/2026_07_27_120000_create_group_channel_technical_delete_tasks_table\.php|\.github/workflows/deploy\.yml|deploy/deploy\.sh)$' \
            >/dev/null; then
        CORE_RESTART_REQUIRED=0
    fi
fi

if [ "$CORE_RESTART_REQUIRED" -eq 1 ]; then
    sudo systemctl restart skyguardian-telethon.service skyguardian-scheduler.service
else
    sudo systemctl is-active --quiet skyguardian-telethon.service \
        || sudo systemctl start skyguardian-telethon.service
    sudo systemctl is-active --quiet skyguardian-scheduler.service \
        || sudo systemctl start skyguardian-scheduler.service
fi

sudo systemctl restart \
    skyguardian-group-channel-telethon.service \
    skyguardian-group-channel-delete.service
sudo systemctl start skyguardian-backup.timer

sudo systemctl reload php8.3-fpm
sudo nginx -t
sudo systemctl reload nginx

curl --fail --silent --show-error --max-time 15 https://skyguardian.pp.ua/up >/dev/null
curl --fail --silent --show-error --max-time 15 https://skyguardian.pp.ua/ >/dev/null
curl --fail --silent --show-error --max-time 15 https://skyguardian.pp.ua/admin/login >/dev/null
test -L public/storage

sudo systemctl --no-pager --full status \
    skyguardian-telethon.service \
    skyguardian-scheduler.service \
    skyguardian-group-channel-telethon.service \
    skyguardian-group-channel-delete.service \
    skyguardian-backup.timer

echo "SkyGuardian deployed successfully."
