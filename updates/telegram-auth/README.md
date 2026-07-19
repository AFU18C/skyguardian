# SkyGuardian — вход через Telegram

Патч добавляет согласованную мобильную страницу авторизации SkyGuardian и проверяемый на сервере вход через Telegram.

## Что добавляется

- адаптивная страница входа в согласованном тёмно-синем стиле;
- кнопка «Войти через Telegram»;
- криптографическая проверка данных Telegram через токен бота;
- ограничение доступа по Telegram ID;
- вход в существующую учётную запись администратора по email;
- резервное копирование заменяемых файлов перед установкой.

## Установка на сервере

```bash
cd /var/www/SkyGuardian
git pull origin main
chmod +x updates/telegram-auth/install.sh
sudo updates/telegram-auth/install.sh /var/www/SkyGuardian
```

Добавить в `.env`:

```dotenv
TELEGRAM_AUTH_BOT_TOKEN=123456789:BOT_TOKEN
TELEGRAM_AUTH_BOT_USERNAME=SkyGuardianBot
TELEGRAM_AUTH_ADMIN_EMAIL=admin@example.com
TELEGRAM_AUTH_ALLOWED_IDS=123456789
TELEGRAM_AUTH_MAX_AGE=300
```

`TELEGRAM_AUTH_ALLOWED_IDS` может содержать несколько Telegram ID через запятую. Оставлять список пустым на рабочем сервере не рекомендуется.

После заполнения `.env`:

```bash
php artisan optimize:clear
php artisan optimize
```

## Настройка Telegram

В BotFather для бота необходимо настроить домен приложения командой `/setdomain`. Домен должен совпадать с доменом, на котором открыт SkyGuardian, и работать по HTTPS.

## Проверка

```bash
php artisan route:list --path=login
php artisan route:list --path=auth/telegram
```

Затем открыть `/login` в браузере. После успешного подтверждения Telegram пользователь будет авторизован как существующий администратор, указанный в `TELEGRAM_AUTH_ADMIN_EMAIL`.
