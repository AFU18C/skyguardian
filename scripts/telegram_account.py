#!/usr/bin/env python3

import argparse
import asyncio
import json
import os
import sys

from telethon import TelegramClient
from telethon.errors import (
    PhoneCodeExpiredError,
    PhoneCodeInvalidError,
    SessionPasswordNeededError,
)


def respond(ok: bool, **payload):
    print(json.dumps({"ok": ok, **payload}, ensure_ascii=False))


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-id", required=True, type=int)
    parser.add_argument("--api-hash", required=True)
    parser.add_argument("--session", required=True)

    subparsers = parser.add_subparsers(dest="command", required=True)

    send_code = subparsers.add_parser("send-code")
    send_code.add_argument("--phone", required=True)

    sign_in = subparsers.add_parser("sign-in")
    sign_in.add_argument("--phone", required=True)
    sign_in.add_argument("--code", required=True)
    sign_in.add_argument("--phone-code-hash", required=True)
    sign_in.add_argument("--password")

    subparsers.add_parser("status")
    subparsers.add_parser("logout")

    args = parser.parse_args()

    session_dir = os.path.dirname(args.session)
    os.makedirs(session_dir, exist_ok=True)

    client = TelegramClient(args.session, args.api_id, args.api_hash)
    await client.connect()

    try:
        if args.command == "send-code":
            sent = await client.send_code_request(args.phone)
            respond(True, status="code_sent", phone_code_hash=sent.phone_code_hash)
            return

        if args.command == "sign-in":
            try:
                await client.sign_in(
                    phone=args.phone,
                    code=args.code,
                    phone_code_hash=args.phone_code_hash,
                )
            except SessionPasswordNeededError:
                if not args.password:
                    respond(True, status="password_required")
                    return
                await client.sign_in(password=args.password)
            except PhoneCodeInvalidError:
                respond(False, message="Невірний код Telegram.")
                sys.exit(1)
            except PhoneCodeExpiredError:
                respond(False, message="Код Telegram прострочений. Надішліть новий код.")
                sys.exit(1)

            me = await client.get_me()
            respond(
                True,
                status="connected",
                account={
                    "id": str(me.id),
                    "name": " ".join(filter(None, [me.first_name, me.last_name])),
                    "username": me.username or "",
                    "phone": me.phone or args.phone,
                },
            )
            return

        if args.command == "status":
            if not await client.is_user_authorized():
                respond(True, status="disconnected")
                return

            me = await client.get_me()
            respond(
                True,
                status="connected",
                account={
                    "id": str(me.id),
                    "name": " ".join(filter(None, [me.first_name, me.last_name])),
                    "username": me.username or "",
                    "phone": me.phone or "",
                },
            )
            return

        if args.command == "logout":
            if await client.is_user_authorized():
                await client.log_out()
            respond(True, status="disconnected")
            return
    finally:
        await client.disconnect()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except Exception as exception:
        respond(False, message=str(exception))
        sys.exit(1)
