#!/usr/bin/env python3

import argparse
import asyncio
import json
import os
import sys
from datetime import datetime, timezone

from telethon import TelegramClient
from telethon.errors import SessionPasswordNeededError


def write_state(path: str, **payload):
    payload["updated_at"] = datetime.now(timezone.utc).isoformat()
    temporary = path + ".tmp"
    with open(temporary, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False)
    os.replace(temporary, path)


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-id", required=True, type=int)
    parser.add_argument("--api-hash", required=True)
    parser.add_argument("--session", required=True)
    parser.add_argument("--state-file", required=True)
    parser.add_argument("--password")
    parser.add_argument("--timeout", type=int, default=120)
    args = parser.parse_args()

    os.makedirs(os.path.dirname(args.session), exist_ok=True)
    os.makedirs(os.path.dirname(args.state_file), exist_ok=True)

    client = TelegramClient(args.session, args.api_id, args.api_hash)
    await client.connect()

    try:
        if await client.is_user_authorized():
            me = await client.get_me()
            write_state(
                args.state_file,
                status="connected",
                account={
                    "id": str(me.id),
                    "name": " ".join(filter(None, [me.first_name, me.last_name])),
                    "username": me.username or "",
                    "phone": me.phone or "",
                },
            )
            return

        qr = await client.qr_login()
        write_state(args.state_file, status="waiting", url=qr.url, expires=qr.expires.isoformat())

        try:
            await qr.wait(timeout=args.timeout)
        except SessionPasswordNeededError:
            if not args.password:
                write_state(args.state_file, status="password_required", message="Для аккаунта включена двухэтапная проверка. Запустите QR-вход повторно и укажите пароль 2FA.")
                return
            await client.sign_in(password=args.password)
        except asyncio.TimeoutError:
            write_state(args.state_file, status="expired", message="QR-код истёк. Создайте новый QR-код.")
            return

        me = await client.get_me()
        write_state(
            args.state_file,
            status="connected",
            account={
                "id": str(me.id),
                "name": " ".join(filter(None, [me.first_name, me.last_name])),
                "username": me.username or "",
                "phone": me.phone or "",
            },
        )
    except Exception as exception:
        write_state(args.state_file, status="error", message=str(exception))
        raise
    finally:
        await client.disconnect()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except Exception:
        sys.exit(1)
