#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="${1:-/var/www/SkyGuardian}"
APP_DIR="${2:-/var/www/SkyGuardianUa}"

[ "$(id -u)" -eq 0 ] || { echo "Запусти от root"; exit 1; }
command -v php >/dev/null || { echo "PHP не найден"; exit 1; }
command -v composer >/dev/null || { echo "Composer не найден"; exit 1; }
[ -d "$SOURCE_DIR/.git" ] || { echo "Репозиторий не найден: $SOURCE_DIR"; exit 1; }
[ ! -e "$APP_DIR/artisan" ] || { echo "Приложение уже существует: $APP_DIR"; exit 1; }

TMP_DIR="$(mktemp -d /tmp/skyguardian.XXXXXX)"
trap 'rm -rf "$TMP_DIR"' EXIT

read -r -p "Email администратора: " ADMIN_EMAIL
read -r -s -p "Пароль администратора (минимум 8 символов): " ADMIN_PASSWORD
echo
[ "${#ADMIN_PASSWORD}" -ge 8 ] || { echo "Пароль слишком короткий"; exit 1; }

COMPOSER_ALLOW_SUPERUSER=1 composer create-project laravel/laravel:^11.0 "$TMP_DIR/app" --no-interaction --prefer-dist
mkdir -p "$APP_DIR"
cp -a "$TMP_DIR/app/." "$APP_DIR/"
cd "$APP_DIR"
cp .env.example .env
php artisan key:generate --force
mkdir -p database
touch database/database.sqlite
sed -i 's/^APP_NAME=.*/APP_NAME=SkyGuardian/' .env
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
sed -i '/^DB_HOST=/d;/^DB_PORT=/d;/^DB_DATABASE=/d;/^DB_USERNAME=/d;/^DB_PASSWORD=/d' .env
printf '\nDB_DATABASE=%s/database/database.sqlite\n' "$APP_DIR" >> .env

mkdir -p resources/views/auth app/Http/Controllers config routes
cp "$SOURCE_DIR/updates/telegram-auth/app/Http/Controllers/TelegramAuthController.php" app/Http/Controllers/
cp "$SOURCE_DIR/updates/telegram-auth/config/telegram-auth.php" config/
cp "$SOURCE_DIR/updates/telegram-auth/resources/views/auth/telegram-login.blade.php" resources/views/auth/
cp "$SOURCE_DIR/updates/telegram-auth/routes/telegram-auth.php" routes/

cat > resources/views/dashboard.blade.php <<'BLADE'
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyGuardian</title><style>*{box-sizing:border-box}body{margin:0;font-family:system-ui;background:#f5f7fb;color:#182235}.app{min-height:100vh;display:grid;grid-template-columns:300px 1fr}.side{background:linear-gradient(180deg,#07172b,#09223b);color:#fff;padding:28px 20px}.home{padding:16px;border-radius:10px;background:#1768d8;font-weight:700}.group{margin-top:28px;padding-bottom:20px;border-bottom:1px solid #203850}.title{font-weight:800;text-transform:uppercase}.sub{padding:10px 28px;color:#d7e1ee}.content{padding:36px;max-width:900px}.card{margin-top:20px;background:#fff;border:1px solid #e5eaf1;border-radius:18px;padding:24px}.metric{font-size:34px;font-weight:800}@media(max-width:760px){.app{grid-template-columns:1fr}.side{display:none}.content{padding:24px 16px}}</style></head><body><div class="app"><aside class="side"><h2>🛡 SkyGuardian</h2><div class="home">⌂ Главная</div><div class="group"><div class="title">▦ Новости</div><div class="sub">• Источники Бота</div><div class="sub">• Настройки Бота</div></div><div class="group"><div class="title">🔔 Воздушная тревога</div><div class="sub">• Источники Бота</div><div class="sub">• Настройки Бота</div></div><div class="group"><div class="title">⚙ Общие настройки</div><div class="sub">• Пользователи</div></div></aside><main class="content"><h1>Главная</h1><p>Добро пожаловать в SkyGuardian</p><div class="card">НОВОСТИ<div class="metric">0</div>источников подключено</div><div class="card">ВОЗДУШНАЯ ТРЕВОГА<div class="metric">0</div>источников подключено</div><div class="card"><h3>Система</h3><p>Статус системы: Работает</p><p>Версия: v1.0.0</p></div></main></div></body></html>
BLADE

cat > routes/web.php <<'PHP'
<?php
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
Route::middleware('auth')->group(function () {
    Route::view('/', 'dashboard')->name('dashboard');
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect('/login');
    })->name('logout');
});
require __DIR__.'/telegram-auth.php';
PHP

if [ -f "$SOURCE_DIR/.env" ]; then
    grep '^TELEGRAM_AUTH_' "$SOURCE_DIR/.env" >> .env || true
fi

php artisan migrate --force
ADMIN_EMAIL="$ADMIN_EMAIL" ADMIN_PASSWORD="$ADMIN_PASSWORD" php artisan tinker --execute='App\Models\User::updateOrCreate(["email"=>getenv("ADMIN_EMAIL")],["name"=>"Administrator","password"=>Illuminate\Support\Facades\Hash::make(getenv("ADMIN_PASSWORD"))]);'
php artisan optimize:clear
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwX storage bootstrap/cache

echo "✅ Приложение создано: $APP_DIR"
echo "Следующий шаг: подключить Nginx после проверки artisan и маршрутов"
php artisan route:list --path=login
php artisan route:list --path=auth/telegram
