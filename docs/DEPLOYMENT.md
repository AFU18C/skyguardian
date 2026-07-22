# SkyGuardian deployment and update

## Requirements

- Ubuntu/Debian VPS with systemd and nginx
- PHP 8.3 CLI/FPM with mbstring, fileinfo, zlib, gmp, gd, iconv and curl
- Composer 2
- Writable `/var/www/SkyGuardianUa/storage` for `www-data`

## GitHub Actions secrets

The deployment workflows require:

- `SKYGUARDIAN_SSH_PRIVATE_KEY`
- `SKYGUARDIAN_SSH_HOST`
- `SKYGUARDIAN_SSH_PORT`
- `SKYGUARDIAN_SSH_USER`

## Automatic deployment

Pushes to `main` trigger application deployment when runtime files change. Worker notification unit files are deployed by the dedicated notification workflow.

The production directory is:

```text
/var/www/SkyGuardianUa
```

## Services

```bash
sudo systemctl status skyguardian-data-news.service
sudo systemctl status skyguardian-data-alerts.service
sudo systemctl status skyguardian-worker-notifications.timer
```

View logs:

```bash
sudo journalctl -u skyguardian-data-news.service -n 100 --no-pager
sudo journalctl -u skyguardian-data-alerts.service -n 100 --no-pager
sudo journalctl -u skyguardian-worker-notifications.service -n 100 --no-pager
```

## Production verification

Run after every deployment:

```bash
cd /var/www/SkyGuardianUa
sudo -u www-data php bin/verify-production.php
```

Warnings do not fail verification. Errors return exit code 1.

## Notification configuration

Configure notifications through the protected admin panel. The token is stored only in:

```text
storage/worker-notifications.json
```

Recommended permissions:

```bash
sudo chown www-data:www-data storage/worker-notifications.json
sudo chmod 600 storage/worker-notifications.json
```

## Manual update

```bash
cd /var/www/SkyGuardianUa
git pull --ff-only origin main
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php bin/install-worker-monitor-ui.php
php bin/install-worker-notifications-ui.php
sudo systemctl daemon-reload
sudo systemctl restart skyguardian-data-news.service skyguardian-data-alerts.service
sudo systemctl restart skyguardian-worker-notifications.timer
sudo -u www-data php bin/verify-production.php
```

## Rollback

1. Select the last known good commit.
2. Deploy that commit through GitHub Actions or check it out on the VPS.
3. Run Composer installation.
4. Restart all SkyGuardian services.
5. Run `bin/verify-production.php`.

Do not delete `storage` during rollback because it contains Telegram sessions, configuration and worker state.
