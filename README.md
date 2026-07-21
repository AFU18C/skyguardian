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

Telegram connections and data processing will be implemented after the interface is approved.
