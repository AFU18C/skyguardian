#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="${1:-/var/www/SkyGuardian}"
APP_DIR="${2:-/var/www/SkyGuardianUa}"

[ "$(id -u)" -eq 0 ] || { echo "Запусти от root"; exit 1; }
[ -f "$APP_DIR/artisan" ] || { echo "Laravel не найден: $APP_DIR"; exit 1; }
[ -f "$SOURCE_DIR/updates/dashboard/resources/views/dashboard.blade.php" ] || { echo "Шаблон не найден в репозитории"; exit 1; }

cd "$APP_DIR"
mkdir -p storage/backups resources/views
STAMP="$(date +%Y%m%d-%H%M%S)"
[ -f resources/views/dashboard.blade.php ] && cp resources/views/dashboard.blade.php "storage/backups/dashboard.blade.php.$STAMP"
cp "$SOURCE_DIR/updates/dashboard/resources/views/dashboard.blade.php" resources/views/dashboard.blade.php

php artisan optimize:clear
chown www-data:www-data resources/views/dashboard.blade.php
chmod 0644 resources/views/dashboard.blade.php

echo "✅ Согласованный шаблон главной установлен"
echo "Резервная копия: storage/backups/dashboard.blade.php.$STAMP"
