# SkyGuardian

Adaptive interface template for the SkyGuardian administration panel.

## Create administrator on the server

```bash
cd /var/www/SkyGuardianUa
php artisan admin:create
```

The command requests the administrator email and password interactively. Password input is hidden and only a secure hash is stored in `storage/admin.json`.

## Local preview

```bash
php -S 127.0.0.1:8080 -t public
```

## Telegram automation

The administration panel can register a protected Telegram webhook for each configured bot. The public site must use HTTPS and `public/telegram-webhook.php` must be reachable from Telegram.

Install the maintenance cron after deployment so expired captchas and delayed deletions are processed even when no new updates arrive:

```bash
sudo cp deploy/skyguardian-telegram.cron /etc/cron.d/skyguardian-telegram
sudo chmod 644 /etc/cron.d/skyguardian-telegram
sudo chown -R www-data:www-data storage
```

Webhook is the recommended mode. For polling, either enable the polling line in the cron template or install `deploy/skyguardian-telegram.service`; never run webhook and polling for the same bot simultaneously.

Runtime tokens, state and logs are stored outside the public directory with mode `0600` and are ignored by Git.
