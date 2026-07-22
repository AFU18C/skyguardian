# SkyGuardian v1.0 release checklist

## CI

- [ ] `Release readiness` is green on the target commit.
- [ ] `Worker resilience checks` is green.
- [ ] Application deployment is green.
- [ ] Worker notification deployment is green.

## Production

- [ ] Admin login opens successfully.
- [ ] Telegram account connection screen opens.
- [ ] News and Alerts channel settings load.
- [ ] News worker service is active.
- [ ] Alerts worker service is active.
- [ ] Notification watcher timer is active.
- [ ] Worker monitoring cards update in the admin dashboard.
- [ ] Notification settings can be saved without exposing the token.
- [ ] Test Telegram notification is delivered.
- [ ] Notification journal records the test delivery.

## End-to-end scenario

- [ ] Add or select a connected technical Telegram account.
- [ ] Configure one test source and destination channel.
- [ ] Run a manual source check.
- [ ] Confirm that manual check updates only availability and manual-check timestamp.
- [ ] Publish a new source message and confirm worker delivery.
- [ ] Confirm that automatic polling does not alter the manual-check timestamp.
- [ ] Trigger a controlled invalid-channel error and confirm one alert.
- [ ] Restore the channel and confirm one recovery alert.

## Server verification

```bash
cd /var/www/SkyGuardianUa
sudo -u www-data php bin/verify-production.php
sudo systemctl is-active skyguardian-data-news.service
sudo systemctl is-active skyguardian-data-alerts.service
sudo systemctl is-active skyguardian-worker-notifications.timer
```

## Security

- [ ] `storage/admin.json` is not publicly accessible.
- [ ] Telegram session files are owned by `www-data` and not world-readable.
- [ ] Notification Bot Token is never present in HTML, API GET responses or logs.
- [ ] All state/configuration JSON files use restrictive permissions.
- [ ] No secrets are committed to GitHub.

## Backup

Before tagging the release, back up:

```text
storage/admin.json
storage/telegram-accounts.json
storage/telegram-news-accounts.json
storage/telegram-news-channels.json
storage/telegram-alerts-channels.json
storage/telegram-sessions/
storage/telegram-news-sessions/
storage/worker-notifications.json
```

## Release

- [ ] Create the stable tag `v1.0.0` from the verified commit.
- [ ] Record the commit SHA and deployment time.
- [ ] Keep the previous known-good commit available for rollback.
