#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="${1:-/var/www/SkyGuardian}"
APP_DIR="${2:-/var/www/SkyGuardianUa}"

[ "$(id -u)" -eq 0 ] || { echo "Запусти от root"; exit 1; }
[ -f "$APP_DIR/artisan" ] || { echo "Laravel не найден: $APP_DIR"; exit 1; }
[ -f "$SOURCE_DIR/updates/sections/routes/sections.php" ] || { echo "Файлы разделов не найдены"; exit 1; }

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$APP_DIR/storage/backups/sections-$STAMP"
mkdir -p "$BACKUP"
cp "$APP_DIR/routes/web.php" "$BACKUP/web.php"
[ -f "$APP_DIR/resources/views/dashboard.blade.php" ] && cp "$APP_DIR/resources/views/dashboard.blade.php" "$BACKUP/dashboard.blade.php"

install -D -m 0644 "$SOURCE_DIR/updates/sections/routes/sections.php" "$APP_DIR/routes/sections.php"
install -D -m 0644 "$SOURCE_DIR/updates/sections/resources/views/section.blade.php" "$APP_DIR/resources/views/section.blade.php"

if ! grep -q "routes/sections.php" "$APP_DIR/routes/web.php"; then
    printf "\nrequire __DIR__.'/sections.php';\n" >> "$APP_DIR/routes/web.php"
fi

python3 - "$APP_DIR/resources/views/dashboard.blade.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
s=p.read_text()
repls={
'<div class="nav-home"><span class="nav-icon">⌂</span><span>Главная</span></div>':'<a class="nav-home" href="{{ route(\'dashboard\') }}"><span class="nav-icon">⌂</span><span>Главная</span></a>',
'<div class="nav-sub"><span class="dot"></span><span>Источники Бота</span></div>':'<a class="nav-sub" href="{{ route(\'news.sources\') }}"><span class="dot"></span><span>Источники Бота</span></a>',
'<div class="nav-sub"><span class="dot"></span><span>Настройки Бота</span></div>':'<a class="nav-sub" href="{{ route(\'news.settings\') }}"><span class="dot"></span><span>Настройки Бота</span></a>',
}
for old,new in repls.items():
    s=s.replace(old,new,1)
s=s.replace('<div class="nav-sub"><span class="dot"></span><span>Источники Бота</span></div>','<a class="nav-sub" href="{{ route(\'alerts.sources\') }}"><span class="dot"></span><span>Источники Бота</span></a>',1)
s=s.replace('<div class="nav-sub"><span class="dot"></span><span>Настройки Бота</span></div>','<a class="nav-sub" href="{{ route(\'alerts.settings\') }}"><span class="dot"></span><span>Настройки Бота</span></a>',1)
s=s.replace('<div class="nav-sub"><span class="dot"></span><span>Пользователи</span></div>','<a class="nav-sub" href="{{ route(\'users.index\') }}"><span class="dot"></span><span>Пользователи</span></a>',1)
s=s.replace('button{font:inherit}', 'button{font:inherit}a{text-decoration:none;color:inherit}')
p.write_text(s)
PY

cd "$APP_DIR"
php artisan optimize:clear
php artisan route:list --path=news
php artisan route:list --path=alerts
php artisan route:list --path=users

chown www-data:www-data routes/sections.php resources/views/section.blade.php resources/views/dashboard.blade.php
chmod 0644 routes/sections.php resources/views/section.blade.php resources/views/dashboard.blade.php

echo "✅ Отдельные страницы разделов установлены"
echo "Резервная копия: $BACKUP"
