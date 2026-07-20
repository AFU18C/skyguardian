# Telegram QR verification checklist

- Open `/group` as an authenticated administrator.
- Save a technical account before using QR login.
- Confirm the QR button becomes active for saved accounts.
- Confirm an unsaved account shows a save-first warning.
- Confirm invalid API ID or API Hash produces a safe error message.
- Confirm the modal refreshes the QR code while waiting.
- Confirm scanning the QR code completes login.
- Confirm two-step verification is requested in the same modal when enabled.
- Confirm the account is marked `connected: true` in runtime storage.
- Confirm a separate session is created under `storage/telegram-sessions/<account-id>`.
- Confirm no session files or secrets appear in Git status.
