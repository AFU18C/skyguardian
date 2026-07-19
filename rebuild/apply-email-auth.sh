#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="${1:-/var/www/SkyGuardian}"
APP_DIR="${2:-/var/www/SkyGuardianUa}"

[ "$(id -u)" -eq 0 ] || { echo "Запусти от root"; exit 1; }
[ -d "$SOURCE_DIR/.git" ] || { echo "Репозиторий не найден: $SOURCE_DIR"; exit 1; }
[ -f "$APP_DIR/artisan" ] || { echo "Laravel не найден: $APP_DIR"; exit 1; }

BACKUP_DIR="$APP_DIR/storage/app/backups/email-auth-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

backup_if_exists() {
    local relative="$1"
    if [ -f "$APP_DIR/$relative" ]; then
        mkdir -p "$BACKUP_DIR/$(dirname "$relative")"
        cp "$APP_DIR/$relative" "$BACKUP_DIR/$relative"
    fi
}

backup_if_exists "app/Http/Controllers/TelegramAuthController.php"
backup_if_exists "app/Http/Controllers/EmailAuthController.php"
backup_if_exists "resources/views/auth/telegram-login.blade.php"
backup_if_exists "routes/telegram-auth.php"
backup_if_exists "config/telegram-auth.php"

install -D -m 0644 \
    "$SOURCE_DIR/updates/telegram-auth/app/Http/Controllers/EmailAuthController.php" \
    "$APP_DIR/app/Http/Controllers/EmailAuthController.php"

install -D -m 0644 \
    "$SOURCE_DIR/updates/telegram-auth/resources/views/auth/telegram-login.blade.php" \
    "$APP_DIR/resources/views/auth/telegram-login.blade.php"

install -D -m 0644 \
    "$SOURCE_DIR/updates/telegram-auth/routes/telegram-auth.php" \
    "$APP_DIR/routes/telegram-auth.php"

rm -f "$APP_DIR/app/Http/Controllers/TelegramAuthController.php"
rm -f "$APP_DIR/config/telegram-auth.php"

cd "$APP_DIR"
php artisan optimize:clear
php artisan route:list --path=login

if php artisan route:list | grep -q 'auth/telegram'; then
    echo "❌ Telegram-маршрут всё ещё найден"
    exit 1
fi

chown -R www-data:www-data \
    app/Http/Controllers/EmailAuthController.php \
    resources/views/auth/telegram-login.blade.php \
    routes/telegram-auth.php \
    storage bootstrap/cache

chmod -R ug+rwX storage bootstrap/cache

echo "✅ Email-авторизация установлена"
echo "✅ Telegram-авторизация удалена"
echo "Резервная копия: $BACKUP_DIR"
