#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR=/var/www/SkyGuardian
ARCHIVE_B64="$PROJECT_DIR/skyguardian-template.tar.gz.b64"

if [ "$(id -u)" -ne 0 ]; then
    echo "Запусти команду от root."
    exit 1
fi

cd "$PROJECT_DIR"

if [ ! -f "$ARCHIVE_B64" ]; then
    echo "Архив шаблона не найден."
    exit 1
fi

read -r -p "Email администратора: " ADMIN_EMAIL
while true; do
    read -r -s -p "Пароль администратора (минимум 8 символов): " ADMIN_PASSWORD
    echo
    if [ "${#ADMIN_PASSWORD}" -ge 8 ]; then
        break
    fi
    echo "Пароль слишком короткий."
done

DB_PASSWORD="$(openssl rand -hex 18)"
APP_IP="$(hostname -I | awk '{print $1}')"

find "$PROJECT_DIR" -mindepth 1 -maxdepth 1 ! -name .git ! -name skyguardian-template.tar.gz.b64 ! -name install-template.sh -exec rm -rf {} +
base64 -d "$ARCHIVE_B64" | tar -xzf - -C "$PROJECT_DIR"
rm -f "$ARCHIVE_B64" "$PROJECT_DIR/install-template.sh"

export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
php artisan key:generate --force

mysql <<SQL
CREATE DATABASE IF NOT EXISTS skyguardian CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'skyguardian'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'skyguardian'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON skyguardian.* TO 'skyguardian'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

sed -i "s|^APP_URL=.*|APP_URL=http://${APP_IP}|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env

php artisan migrate --force
php artisan app:create-admin "$ADMIN_EMAIL" --password="$ADMIN_PASSWORD"
php artisan optimize

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data "$PROJECT_DIR"
chmod -R ug+rwX storage bootstrap/cache

cat >/etc/nginx/sites-available/skyguardian <<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root /var/www/SkyGuardian/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
NGINX

rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/skyguardian /etc/nginx/sites-enabled/skyguardian
nginx -t
systemctl reload nginx

cd "$PROJECT_DIR"
git add -A
git commit -m "Add mobile administration template"
git push origin main

echo ""
echo "SkyGuardian установлен: http://${APP_IP}"
echo "Администратор: ${ADMIN_EMAIL}"
echo "Проверка сервисов:"
systemctl is-active nginx php8.3-fpm mysql redis-server supervisor
