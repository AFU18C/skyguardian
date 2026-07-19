#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${1:-/var/www/SkyGuardianUa}"
SOURCE_DIR="${2:-/var/www/SkyGuardian}"

[ "$(id -u)" -eq 0 ] || { echo "Запусти от root"; exit 1; }
[ -f "$APP_DIR/artisan" ] || { echo "Laravel не найден: $APP_DIR"; exit 1; }
[ -d "$SOURCE_DIR/.git" ] || { echo "Репозиторий не найден: $SOURCE_DIR"; exit 1; }

PHP_MM="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if ! php -m | grep -qi '^pdo_sqlite$'; then
    apt-get update
    apt-get install -y "php${PHP_MM}-sqlite3"
    systemctl restart "php${PHP_MM}-fpm" 2>/dev/null || true
fi

php -m | grep -qi '^pdo_sqlite$' || { echo "pdo_sqlite не загрузился"; exit 1; }

read -r -p "Email администратора: " ADMIN_EMAIL
read -r -s -p "Пароль администратора (минимум 8 символов): " ADMIN_PASSWORD
echo
[ "${#ADMIN_PASSWORD}" -ge 8 ] || { echo "Пароль слишком короткий"; exit 1; }

cd "$APP_DIR"
mkdir -p database
touch database/database.sqlite

if ! grep -q '^DB_CONNECTION=sqlite$' .env; then
    sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
fi
sed -i '/^DB_HOST=/d;/^DB_PORT=/d;/^DB_USERNAME=/d;/^DB_PASSWORD=/d' .env
if grep -q '^DB_DATABASE=' .env; then
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$APP_DIR/database/database.sqlite|" .env
else
    printf '\nDB_DATABASE=%s/database/database.sqlite\n' "$APP_DIR" >> .env
fi

mkdir -p resources/views/auth app/Http/Controllers config routes
cp "$SOURCE_DIR/updates/telegram-auth/app/Http/Controllers/TelegramAuthController.php" app/Http/Controllers/
cp "$SOURCE_DIR/updates/telegram-auth/config/telegram-auth.php" config/
cp "$SOURCE_DIR/updates/telegram-auth/resources/views/auth/telegram-login.blade.php" resources/views/auth/
cp "$SOURCE_DIR/updates/telegram-auth/routes/telegram-auth.php" routes/

if [ -f "$SOURCE_DIR/.env" ]; then
    while IFS= read -r line; do
        key="${line%%=*}"
        sed -i "/^${key}=/d" .env
        printf '%s\n' "$line" >> .env
    done < <(grep '^TELEGRAM_AUTH_' "$SOURCE_DIR/.env" || true)
fi

php artisan migrate --force
ADMIN_EMAIL="$ADMIN_EMAIL" ADMIN_PASSWORD="$ADMIN_PASSWORD" php artisan tinker --execute='App\Models\User::updateOrCreate(["email"=>getenv("ADMIN_EMAIL")],["name"=>"Administrator","password"=>Illuminate\Support\Facades\Hash::make(getenv("ADMIN_PASSWORD"))]);'
php artisan optimize:clear
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwX storage bootstrap/cache

php artisan route:list --path=login
php artisan route:list --path=auth/telegram

echo "✅ Установка продолжена успешно: $APP_DIR"
