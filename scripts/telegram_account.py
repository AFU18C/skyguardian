#!/usr/bin/env python3

import argparse
import asyncio
import json
import os
import sys
from datetime import datetime, timedelta, timezone

from telethon import TelegramClient
from telethon.errors import (
    PhoneCodeExpiredError,
    PhoneCodeInvalidError,
    SessionPasswordNeededError,
)
from telethon.tl.types import Channel, Chat


def respond(ok: bool, **payload):
    print(json.dumps({"ok": ok, **payload}, ensure_ascii=False))


async def check_chat(client, chat_ref: str, mode: str):
    if not await client.is_user_authorized():
        respond(False, message="Технический аккаунт не подключён.")
        return

    entity = await client.get_entity(chat_ref)
    me = await client.get_me()
    permissions = await client.get_permissions(entity, me)

    is_creator = bool(getattr(permissions, "is_creator", False))
    is_admin = bool(getattr(permissions, "is_admin", False) or is_creator)
    admin_rights = getattr(permissions, "admin_rights", None)
    banned_rights = getattr(permissions, "banned_rights", None)

    if isinstance(entity, Channel):
        if entity.broadcast:
            chat_type = "channel"
            can_send = bool(
                is_creator
                or (
                    is_admin
                    and (
                        getattr(admin_rights, "post_messages", False)
                        or getattr(admin_rights, "edit_messages", False)
                    )
                )
            )
            publish_as = "channel"
        else:
            chat_type = "group"
            can_send = bool(
                is_creator
                or not getattr(banned_rights, "send_messages", False)
            )
            anonymous = bool(is_admin and getattr(admin_rights, "anonymous", False))
            publish_as = "group" if anonymous else "account"
    elif isinstance(entity, Chat):
        chat_type = "group"
        can_send = bool(
            is_creator
            or not getattr(banned_rights, "send_messages", False)
        )
        publish_as = "account"
    else:
        chat_type = "user"
        can_send = True
        publish_as = "account"

    can_read = True
    allowed = can_read if mode == "source" else can_send

    respond(
        True,
        status="available" if allowed else "no_permission",
        title=getattr(entity, "title", None) or getattr(entity, "username", None) or str(getattr(entity, "id", "")),
        chat_type=chat_type,
        can_read=can_read,
        can_send=can_send,
        is_admin=is_admin,
        is_creator=is_creator,
        publish_as=publish_as,
    )


async def latest_message(client, chat_ref: str):
    if not await client.is_user_authorized():
        respond(False, message="Технический аккаунт не подключён.")
        return

    entity = await client.get_entity(chat_ref)
    messages = await client.get_messages(entity, limit=1)
    latest_id = messages[0].id if messages else 0
    respond(True, latest_message_id=latest_id)


async def delete_messages(client, chat_ref: str, period: str):
    if not await client.is_user_authorized():
        respond(False, message="Технический аккаунт не подключён.")
        return

    entity = await client.get_entity(chat_ref)
    me = await client.get_me()
    permissions = await client.get_permissions(entity, me)
    is_creator = bool(getattr(permissions, "is_creator", False))
    is_admin = bool(getattr(permissions, "is_admin", False) or is_creator)
    admin_rights = getattr(permissions, "admin_rights", None)

    if isinstance(entity, (Channel, Chat)):
        can_delete = bool(
            is_creator
            or (is_admin and getattr(admin_rights, "delete_messages", False))
        )
        if not can_delete:
            respond(False, message="У технического аккаунта нет права удаления сообщений в этом чате.")
            return

    cutoff = None
    if period in {"1", "10"}:
        cutoff = datetime.now(timezone.utc) - timedelta(days=int(period))

    deleted = 0
    batch = []

    async for message in client.iter_messages(entity):
        if cutoff and message.date < cutoff:
            break

        batch.append(message.id)

        if len(batch) >= 100:
            await client.delete_messages(entity, batch, revoke=True)
            deleted += len(batch)
            batch = []

    if batch:
        await client.delete_messages(entity, batch, revoke=True)
        deleted += len(batch)

    respond(True, deleted=deleted, message=f"Удалено сообщений: {deleted}.")


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

    check = subparsers.add_parser("check-chat")
    check.add_argument("--chat", required=True)
    check.add_argument("--mode", required=True, choices=["source", "destination"])

    latest = subparsers.add_parser("latest-message")
    latest.add_argument("--chat", required=True)

    cleanup = subparsers.add_parser("delete-messages")
    cleanup.add_argument("--chat", required=True)
    cleanup.add_argument("--period", required=True, choices=["1", "10", "all"])

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
                respond(False, message="Неверный код Telegram.")
                sys.exit(1)
            except PhoneCodeExpiredError:
                respond(False, message="Код Telegram просрочен. Отправьте новый код.")
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

        if args.command == "check-chat":
            await check_chat(client, args.chat, args.mode)
            return

        if args.command == "latest-message":
            await latest_message(client, args.chat)
            return

        if args.command == "delete-messages":
            await delete_messages(client, args.chat, args.period)
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
