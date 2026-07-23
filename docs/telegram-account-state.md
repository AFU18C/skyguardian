# Telegram technical account state

The single source of truth is `storage/technical-accounts/telegram.json`.

All pages and devices must read and write through `/technical-accounts.php`. Browser localStorage may only cache the canonical list under `skyguardian:telegram:technical-accounts` and must never overwrite a connected server record with `connected=false`.

QR authorization writes directly to the canonical file after MadelineProto reports `LOGGED_IN`.
