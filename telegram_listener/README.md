# SkyGuardian Telegram listener

Minimal direct relay used to verify the first working chain:

`source channel -> Telegram account (Telethon) -> text processing -> target group`

The same technical Telegram account reads and publishes messages. Laravel and the administration panel are not involved at this stage.

## Server setup

```bash
cd /var/www/SkyGuardian/telegram_listener
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
cp .env.example .env
nano .env
.venv/bin/python -m unittest -v
.venv/bin/python listener.py
```

On the first start Telegram asks for the login code and, when enabled, the two-step verification password. The resulting `.session` file must stay only on the server.

`SOURCE_CHAT` and `TARGET_CHAT` accept a public `@username` or a numeric Telegram entity ID available to the connected account.

## Current processing

The verification version only:

- ignores empty messages;
- removes HTTP/HTTPS links;
- removes a standalone `@source_channel` signature line;
- collapses excessive blank lines;
- optionally prepends `MESSAGE_PREFIX`;
- publishes immediately with link previews disabled.

Complex filters, templates and administration settings are intentionally postponed until the relay is verified with real Telegram credentials.
