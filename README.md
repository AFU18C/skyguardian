# SkyGuardian

SkyGuardian — Laravel-приложение для работы с Telegram-источниками через один постоянный процесс Telethon.

## Состав ТЗ №1

- Telegram API-конфигурации;
- технические Telegram-аккаунты;
- авторизация по номеру телефона, коду, 2FA и QR;
- источники типов `news` и `air_alert`;
- правила источников;
- ручная проверка аккаунтов и источников;
- автоматическая обработка по `next_check_at`;
- лимит 20 технических аккаунтов и 40 источников;
- максимум 5 одновременных операций глобально и 2 на аккаунт;
- один постоянный Telethon daemon;
- без Redis, очередей, RabbitMQ, Kafka и микросервисов.

## Runtime

- PHP 8.3;
- Laravel 13;
- MySQL;
- Python 3 + Telethon;
- Laravel Scheduler;
- systemd.

## Основные команды

```bash
php artisan skyguardian:telegram:send-code ACCOUNT_ID
php artisan skyguardian:telegram:sign-in ACCOUNT_ID CODE
php artisan skyguardian:telegram:password ACCOUNT_ID
php artisan skyguardian:telegram:qr ACCOUNT_ID
php artisan skyguardian:account:check ACCOUNT_ID
php artisan skyguardian:source:check SOURCE_ID
php artisan skyguardian:sources:process --limit=40
```

Ручная проверка аккаунта обновляет только состояние подключения, Telegram-профиль, ошибку и время ручной проверки. Она не читает сообщения и не изменяет `last_message_id`.

Автоматическая обработка не выполняет предварительную health-проверку и не изменяет ручной статус или `last_manual_check_at` источника.

## Локальный запуск

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
python3 -m venv .venv
.venv/bin/pip install -r telethon/requirements.txt
```

В отдельных терминалах:

```bash
.venv/bin/python telethon/worker.py
php artisan schedule:work
```

## Проверки

```bash
php artisan test
vendor/bin/pint --test
python3 -m py_compile telethon/worker.py
```
