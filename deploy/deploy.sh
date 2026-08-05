#!/usr/bin/env bash
set -Eeuo pipefail

APP_LINK="/var/www/skyguardian"
DEPLOY_ROOT="/var/www/skyguardian-deploy"
RELEASES_DIR="$DEPLOY_ROOT/releases"
SHARED_DIR="$DEPLOY_ROOT/shared"
WORKSPACE="${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
CURRENT_SHA="${DEPLOY_SHA:-${GITHUB_SHA:-$(git -C "$WORKSPACE" rev-parse HEAD)}}"
RELEASE_ID="${CURRENT_SHA:0:12}-$(date -u +%Y%m%dT%H%M%SZ)"
BUILD_DIR="$RELEASES_DIR/.build-$RELEASE_ID"
RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
PREVIOUS_TARGET=""
LEGACY_DIR=""
SWITCHED=0

if [ -L "$APP_LINK" ]; then
    PREVIOUS_TARGET="$(readlink -f "$APP_LINK")"
elif [ -d "$APP_LINK" ]; then
    LEGACY_DIR="${APP_LINK}-legacy-$(date -u +%Y%m%dT%H%M%SZ)"
fi

sudo install -d -o github-runner -g www-data -m 775 "$DEPLOY_ROOT" "$RELEASES_DIR"
sudo install -d -o github-runner -g www-data -m 750 "$SHARED_DIR"

if [ ! -f "$SHARED_DIR/.env" ]; then
    if [ -f "$APP_LINK/.env" ]; then
        sudo cp -p "$APP_LINK/.env" "$SHARED_DIR/.env"
    else
        cp "$WORKSPACE/.env.example" "$SHARED_DIR/.env"
    fi
fi

sudo install -d -o www-data -g www-data -m 775 \
    "$SHARED_DIR/storage/app/public" \
    "$SHARED_DIR/storage/framework/cache" \
    "$SHARED_DIR/storage/framework/sessions" \
    "$SHARED_DIR/storage/framework/views" \
    "$SHARED_DIR/storage/logs/archive"

if [ -d "$APP_LINK/storage" ] && [ ! -f "$SHARED_DIR/.storage-imported" ]; then
    sudo rsync -a "$APP_LINK/storage/" "$SHARED_DIR/storage/"
    sudo touch "$SHARED_DIR/.storage-imported"
fi

sudo chown -R www-data:www-data "$SHARED_DIR/storage"
sudo chmod -R ug+rwX "$SHARED_DIR/storage"
sudo touch "$SHARED_DIR/storage/logs/laravel.log"
sudo chown www-data:www-data "$SHARED_DIR/storage/logs/laravel.log"
sudo chmod 664 "$SHARED_DIR/storage/logs/laravel.log"
sudo chown github-runner:www-data "$SHARED_DIR/.env"
sudo chmod 640 "$SHARED_DIR/.env"

# The new strict backup implementation is installed before the pre-deploy
# snapshot so a broken or incomplete archive aborts the deployment.
sudo cp "$WORKSPACE/deploy/backup/skyguardian-full-backup.sh" /usr/local/sbin/skyguardian-full-backup
sudo chmod 750 /usr/local/sbin/skyguardian-full-backup
if sudo systemctl list-unit-files skyguardian-backup.service --no-legend 2>/dev/null | grep -q skyguardian-backup; then
    sudo systemctl start skyguardian-backup.service
fi

mkdir -p "$BUILD_DIR"
rsync -a --delete \
    --exclude=".git" \
    --exclude=".github" \
    --exclude=".env" \
    --exclude=".venv" \
    --exclude="node_modules" \
    --exclude="vendor" \
    --exclude="storage" \
    --exclude="public/storage" \
    "$WORKSPACE/" "$BUILD_DIR/"

ln -s "$SHARED_DIR/.env" "$BUILD_DIR/.env"
ln -s "$SHARED_DIR/storage" "$BUILD_DIR/storage"
ln -s "$SHARED_DIR/storage/app/public" "$BUILD_DIR/public/storage"

cd "$BUILD_DIR"

composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

python3 -m venv .venv
.venv/bin/pip install --disable-pip-version-check -r telethon/requirements.txt

npm ci --no-audit --no-fund
npm run build

# Laravel caches may contain absolute paths. Finalize the immutable release
# path before generating any cache so those paths remain valid after switch.
cd "$RELEASES_DIR"
mv "$BUILD_DIR" "$RELEASE_DIR"
cd "$RELEASE_DIR"

upsert_env() {
    local key="$1"
    local value="$2"
    local env_file="$SHARED_DIR/.env"

    if grep -qE "^${key}=" "$env_file"; then
        sed -i "s#^${key}=.*#${key}=${value}#" "$env_file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$env_file"
    fi
}

if ! grep -Eq '^APP_KEY=base64:.+' "$SHARED_DIR/.env"; then
    php artisan key:generate --force
fi

upsert_env LOG_CHANNEL stack
upsert_env LOG_STACK daily
upsert_env LOG_DAILY_DAYS 14
upsert_env LOG_LEVEL info
upsert_env SESSION_SECURE_COOKIE true
upsert_env SESSION_HTTP_ONLY true
upsert_env SESSION_SAME_SITE lax

sudo chown github-runner:www-data "$SHARED_DIR/.env"
sudo chmod 640 "$SHARED_DIR/.env"
sudo -u www-data test -r "$SHARED_DIR/.env"

sudo install -d -o www-data -g www-data -m 775 bootstrap/cache
# Do not run optimize:clear here: it also executes cache:clear and deletes
# active grouped-alert message IDs, start times and region state.
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan clear-compiled
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan schedule:list --no-ansi >/dev/null

sudo cp "$RELEASE_DIR/deploy/systemd/skyguardian-telethon.service" /etc/systemd/system/skyguardian-telethon.service
sudo cp "$RELEASE_DIR/deploy/systemd/skyguardian-scheduler.service" /etc/systemd/system/skyguardian-scheduler.service
sudo cp "$RELEASE_DIR/deploy/systemd/skyguardian-group-channel-telethon.service" /etc/systemd/system/skyguardian-group-channel-telethon.service
sudo cp "$RELEASE_DIR/deploy/systemd/skyguardian-group-channel-delete.service" /etc/systemd/system/skyguardian-group-channel-delete.service
sudo cp "$RELEASE_DIR/deploy/systemd/skyguardian-backup.service" /etc/systemd/system/skyguardian-backup.service
sudo cp "$RELEASE_DIR/deploy/systemd/skyguardian-backup.timer" /etc/systemd/system/skyguardian-backup.timer

sudo install -d -o root -g root -m 700 /var/backups/skyguardian
sudo install -d -o root -g www-data -m 750 /var/lib/skyguardian-backup
sudo install -d -o root -g root -m 700 /etc/skyguardian

if ! sudo test -f /etc/skyguardian/backup.key; then
    sudo sh -c 'umask 077; openssl rand -base64 48 > /etc/skyguardian/backup.key'
fi
if ! sudo test -f /etc/skyguardian/backup.env; then
    printf '%s\n' \
        '# Optional encrypted offsite backup using rclone.' \
        '# Example: RCLONE_REMOTE=remote:skyguardian-backups' \
        'RCLONE_REMOTE=' \
        'BACKUP_ENCRYPTION_PASSWORD_FILE=/etc/skyguardian/backup.key' \
        | sudo tee "$SHARED_DIR/.backup-env-tmp" >/dev/null
    sudo mv "$SHARED_DIR/.backup-env-tmp" /etc/skyguardian/backup.env
    sudo chmod 600 /etc/skyguardian/backup.env
fi

SUDOERS_FILE="$(mktemp)"
trap 'rm -f "$SUDOERS_FILE"' EXIT
printf '%s\n' \
    'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start --no-block skyguardian-backup.service' \
    > "$SUDOERS_FILE"
sudo visudo -cf "$SUDOERS_FILE"
sudo install -o root -g root -m 440 "$SUDOERS_FILE" /etc/sudoers.d/skyguardian-backup
rm -f "$SUDOERS_FILE"
trap - EXIT

sudo systemctl daemon-reload
sudo systemctl enable \
    skyguardian-telethon.service \
    skyguardian-scheduler.service \
    skyguardian-group-channel-telethon.service \
    skyguardian-group-channel-delete.service \
    skyguardian-backup.timer

atomic_link() {
    local target="$1"
    local temporary="${APP_LINK}.next-$RELEASE_ID"

    sudo ln -s "$target" "$temporary"
    sudo mv -Tf "$temporary" "$APP_LINK"
}

rollback() {
    local result=$?
    if [ "$result" -eq 0 ] || [ "$SWITCHED" -ne 1 ]; then
        return "$result"
    fi

    echo "Deployment failed after release switch; restoring previous release." >&2
    if [ -n "$PREVIOUS_TARGET" ] && [ -d "$PREVIOUS_TARGET" ]; then
        atomic_link "$PREVIOUS_TARGET"
    elif [ -n "$LEGACY_DIR" ] && [ -d "$LEGACY_DIR" ]; then
        sudo rm -f "$APP_LINK"
        sudo mv "$LEGACY_DIR" "$APP_LINK"
    fi

    sudo systemctl restart \
        skyguardian-telethon.service \
        skyguardian-scheduler.service \
        skyguardian-group-channel-telethon.service \
        skyguardian-group-channel-delete.service || true
    sudo systemctl reload php8.3-fpm || true

    return "$result"
}
trap rollback EXIT

if [ -n "$LEGACY_DIR" ]; then
    sudo mv "$APP_LINK" "$LEGACY_DIR"
    sudo ln -s "$RELEASE_DIR" "$APP_LINK"
else
    atomic_link "$RELEASE_DIR"
fi
SWITCHED=1

sudo systemctl restart \
    skyguardian-telethon.service \
    skyguardian-scheduler.service \
    skyguardian-group-channel-telethon.service \
    skyguardian-group-channel-delete.service
sudo systemctl start skyguardian-backup.timer

sudo systemctl reload php8.3-fpm
sudo nginx -t
sudo systemctl reload nginx

curl --fail --silent --show-error --max-time 15 https://skyguardian.pp.ua/up >/dev/null
curl --fail --silent --show-error --max-time 15 https://skyguardian.pp.ua/ >/dev/null
curl --fail --silent --show-error --max-time 15 https://skyguardian.pp.ua/admin/login >/dev/null
test -L "$APP_LINK"
test "$(readlink -f "$APP_LINK")" = "$RELEASE_DIR"
test -L "$APP_LINK/public/storage"

sudo systemctl --no-pager --full status \
    skyguardian-telethon.service \
    skyguardian-scheduler.service \
    skyguardian-group-channel-telethon.service \
    skyguardian-group-channel-delete.service \
    skyguardian-backup.timer

SWITCHED=0
trap - EXIT

mapfile -t OLD_RELEASES < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d ! -name '.build-*' -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2- | tail -n +6)
for old_release in "${OLD_RELEASES[@]}"; do
    resolved="$(readlink -f "$old_release")"
    if [[ "$resolved" == "$RELEASES_DIR"/* ]] && [ "$resolved" != "$RELEASE_DIR" ] && [ "$resolved" != "$PREVIOUS_TARGET" ]; then
        sudo rm -rf -- "$resolved"
    fi
done

echo "SkyGuardian release $RELEASE_ID deployed successfully."
