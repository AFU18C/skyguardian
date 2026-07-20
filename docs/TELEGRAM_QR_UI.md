# Telegram QR connection

The technical account must be saved before QR login starts.

Flow:

1. Open the saved technical account.
2. Enter valid Telegram API ID and API Hash.
3. Click **Подключить по QR-коду**.
4. Scan the QR code in Telegram under **Settings → Devices → Link Desktop Device**.
5. If Telegram requests a two-step verification password, enter it in the same modal.
6. After successful authorization, the account is marked as connected in `storage/skyguardian.json` and its independent MadelineProto session remains under `storage/telegram-sessions/<account-id>`.

Security controls:

- the endpoint requires an authenticated SkyGuardian session;
- every request is protected by the existing CSRF token;
- account identifiers are restricted before being used in session paths;
- Telegram session files remain outside Git through the ignored `storage` runtime paths;
- API errors are logged server-side without returning secrets to the browser.
