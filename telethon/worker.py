#!/usr/bin/env python3
import asyncio
import json
import sys
from typing import Any

from telethon import TelegramClient
from telethon.sessions import StringSession


def emit(payload: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(payload, ensure_ascii=False))


async def main() -> None:
    request = json.loads(sys.stdin.read() or "{}")
    action = request.get("action")
    api_id = int(request["api_id"])
    api_hash = request["api_hash"]
    session = request.get("session") or ""
    phone = request.get("phone")
    payload = request.get("payload") or {}

    client = TelegramClient(StringSession(session), api_id, api_hash)

    try:
        await client.connect()

        if action == "check":
            if not await client.is_user_authorized():
                raise RuntimeError("Технический аккаунт не авторизован в Telegram.")
            me = await client.get_me()
            emit({
                "ok": True,
                "session": client.session.save(),
                "user": {
                    "id": me.id,
                    "username": me.username,
                    "first_name": me.first_name,
                    "last_name": me.last_name,
                    "phone": me.phone,
                },
            })
            return

        if action == "send_code":
            if not phone:
                raise RuntimeError("Не указан номер телефона.")
            sent = await client.send_code_request(phone)
            emit({
                "ok": True,
                "session": client.session.save(),
                "phone_code_hash": sent.phone_code_hash,
            })
            return

        if action == "sign_in":
            if not phone:
                raise RuntimeError("Не указан номер телефона.")
            await client.sign_in(
                phone=phone,
                code=str(payload.get("code", "")),
                phone_code_hash=payload.get("phone_code_hash"),
            )
            me = await client.get_me()
            emit({
                "ok": True,
                "session": client.session.save(),
                "user": {
                    "id": me.id,
                    "username": me.username,
                    "first_name": me.first_name,
                    "last_name": me.last_name,
                    "phone": me.phone,
                },
            })
            return

        if action == "sign_in_password":
            await client.sign_in(password=str(payload.get("password", "")))
            me = await client.get_me()
            emit({
                "ok": True,
                "session": client.session.save(),
                "user": {
                    "id": me.id,
                    "username": me.username,
                    "first_name": me.first_name,
                    "last_name": me.last_name,
                    "phone": me.phone,
                },
            })
            return

        raise RuntimeError(f"Неизвестное действие: {action}")
    finally:
        await client.disconnect()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except Exception as exc:
        emit({"ok": False, "error": str(exc)})
        sys.exit(1)
