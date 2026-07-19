#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-/var/www/SkyGuardian}"
PATCH_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="$PROJECT_DIR/storage/app/backups/telegram-auth-$(date +%Y%m%d-%H%M%S)"

if [ ! -f "$PROJECT_DIR/artisan" ] || [ ! -f "$PROJECT_DIR/routes/web.php" ]; then
    echo "Laravel-проект SkyGuardian не найден: $PROJECT_DIR"
    exit 1
fi

mkdir -p "$BACKUP_DIR"

backup_if_exists() {
    local relative="$1"
    if [ -f "$PROJECT_DIR/$relative" ]; then
        mkdir -p "$BACKUP_DIR/$(dirname "$relative")"
        cp "$PROJECT_DIR/$relative" "$BACKUP_DIR/$relative"
    fi
}

backup_if_exists "app/Http/Controllers/TelegramAuthController.php"
backup_if_exists "config/telegram-auth.php"
backup_if_exists "resources/views/auth/telegram-login.blade.php"
backup_if_exists "routes/telegram-auth.php"
backup_if_exists "routes/web.php"
backup_if_exists ".env.example"

install -D -m 0644 "$PATCH_DIR/app/Http/Controllers/TelegramAuthController.php" "$PROJECT_DIR/app/Http/Controllers/TelegramAuthController.php"
install -D -m 0644 "$PATCH_DIR/config/telegram-auth.php" "$PROJECT_DIR/config/telegram-auth.php"
install -D -m 0644 "$PATCH_DIR/resources/views/auth/telegram-login.blade.php" "$PROJECT_DIR/resources/views/auth/telegram-login.blade.php"
install -D -m 0644 "$PATCH_DIR/routes/telegram-auth.php" "$PROJECT_DIR/routes/telegram-auth.php"

ROUTE_REQUIRE="require __DIR__.'/telegram-auth.php';"
if ! grep -Fq "$ROUTE_REQUIRE" "$PROJECT_DIR/routes/web.php"; then
    printf '\n%s\n' "$ROUTE_REQUIRE" >> "$PROJECT_DIR/routes/web.php"
fi

append_env_example() {
    local key="$1"
    local value="$2"
    if ! grep -q "^${key}=" "$PROJECT_DIR/.env.example"; then
        printf '%s=%s\n' "$key" "$value" >> "$PROJECT_DIR/.env.example"
    fi
}

append_env_example "TELEGRAM_AUTH_BOT_TOKEN" ""
append_env_example "TELEGRAM_AUTH_BOT_USERNAME" ""
append_env_example "TELEGRAM_AUTH_ADMIN_EMAIL" ""
append_env_example "TELEGRAM_AUTH_ALLOWED_IDS" ""
append_env_example "TELEGRAM_AUTH_MAX_AGE" "300"

cd "$PROJECT_DIR"
php artisan optimize:clear
php artisan route:list --path=login
php artisan route:list --path=auth/telegram

chown -R www-data:www-data \
    app/Http/Controllers/TelegramAuthController.php \
    config/telegram-auth.php \
    resources/views/auth/telegram-login.blade.php \
    routes/telegram-auth.php

echo ""
echo "Патч установлен. Резервная копия: $BACKUP_DIR"
echo "Заполни TELEGRAM_AUTH_* в $PROJECT_DIR/.env и выполни: php artisan optimize"
