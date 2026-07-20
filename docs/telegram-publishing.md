# Telegram publishing

## Requirements

- PHP 8.2+
- Composer dependencies installed with `composer install --no-dev --optimize-autoloader`
- writable `storage/` directory
- Telegram API ID and API Hash for each technical account

## Connect an account

1. Open **Управление группой**.
2. Save the technical account.
3. Click **Подключить Telegram**.
4. Scan the QR code in Telegram: **Настройки → Устройства → Подключить устройство**.
5. Return to the channel form and select the connected account.

## Automatic publishing

Run the polling worker every minute. Each channel still respects its own configured interval.

```cron
* * * * * cd /path/to/skyguardian && /usr/bin/php bin/poll.php >> storage/poll.log 2>&1
```

For intervals shorter than one minute, run the worker as a systemd service:

```ini
[Unit]
Description=SkyGuardian Telegram publisher
After=network-online.target

[Service]
Type=simple
WorkingDirectory=/path/to/skyguardian
ExecStart=/bin/sh -c 'while true; do /usr/bin/php bin/poll.php; sleep 3; done'
Restart=always
RestartSec=3
User=www-data

[Install]
WantedBy=multi-user.target
```

The worker uses a lock file, so overlapping runs exit safely.
