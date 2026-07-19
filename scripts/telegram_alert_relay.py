#!/usr/bin/env python3

import argparse
import asyncio
import json
import os
import shutil
import sys
import tempfile
from telethon import TelegramClient


def respond(ok: bool, **payload):
    print(json.dumps({"ok": ok, **payload}, ensure_ascii=False))


def compact_error(exception: Exception, limit: int = 1500) -> str:
    message = str(exception).strip() or exception.__class__.__name__
    return message[:limit]


async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--reader-api-id", required=True, type=int)
    parser.add_argument("--reader-api-hash", required=True)
    parser.add_argument("--reader-session", required=True)
    parser.add_argument("--publisher-api-id", required=True, type=int)
    parser.add_argument("--publisher-api-hash", required=True)
    parser.add_argument("--publisher-session", required=True)
    parser.add_argument("--source", required=True)
    parser.add_argument("--destination", required=True)
    parser.add_argument("--after-id", type=int, default=0)
    parser.add_argument("--limit", type=int, default=20)
    args = parser.parse_args()

    os.makedirs(os.path.dirname(args.reader_session), exist_ok=True)
    os.makedirs(os.path.dirname(args.publisher_session), exist_ok=True)

    reader = TelegramClient(args.reader_session, args.reader_api_id, args.reader_api_hash)
    same_account = (
        args.reader_session == args.publisher_session
        and args.reader_api_id == args.publisher_api_id
        and args.reader_api_hash == args.publisher_api_hash
    )
    publisher = reader if same_account else TelegramClient(
        args.publisher_session,
        args.publisher_api_id,
        args.publisher_api_hash,
    )

    await reader.connect()
    if not same_account:
        await publisher.connect()

    temp_dir = tempfile.mkdtemp(prefix="skyguardian-relay-")

    try:
        if not await reader.is_user_authorized():
            raise RuntimeError("Аккаунт для чтения не подключён.")
        if not await publisher.is_user_authorized():
            raise RuntimeError("Аккаунт для публикации не подключён.")

        source = await reader.get_entity(args.source)
        destination = await publisher.get_entity(args.destination)

        if args.after_id <= 0:
            latest = await reader.get_messages(source, limit=1)
            latest_id = latest[0].id if latest else 0
            respond(True, initialized=True, last_message_id=latest_id, received=0, published=0)
            return

        messages = await reader.get_messages(
            source,
            min_id=args.after_id,
            limit=max(1, min(args.limit, 100)),
            reverse=True,
        )

        published = 0
        processed = 0
        last_message_id = args.after_id

        for message in messages:
            text = message.message or ""

            try:
                sent = False

                if message.media:
                    media_path = await message.download_media(file=temp_dir)
                    if media_path:
                        await publisher.send_file(
                            destination,
                            media_path,
                            caption=text or None,
                            formatting_entities=message.entities if text else None,
                        )
                        sent = True
                    elif text:
                        await publisher.send_message(
                            destination,
                            text,
                            formatting_entities=message.entities,
                        )
                        sent = True
                elif text:
                    await publisher.send_message(
                        destination,
                        text,
                        formatting_entities=message.entities,
                    )
                    sent = True

                # Пустые или неподдерживаемые служебные сообщения считаются обработанными,
                # иначе relay будет бесконечно получать одно и то же сообщение.
                last_message_id = max(last_message_id, message.id)
                processed += 1
                if sent:
                    published += 1
            except Exception as exception:
                # Уже успешно обработанные сообщения фиксируются, поэтому при следующем
                # запуске они не будут опубликованы повторно.
                respond(
                    True,
                    initialized=False,
                    partial_failure=True,
                    message=compact_error(exception),
                    failed_message_id=message.id,
                    last_message_id=last_message_id,
                    received=len(messages),
                    processed=processed,
                    published=published,
                )
                return

        respond(
            True,
            initialized=False,
            partial_failure=False,
            last_message_id=last_message_id,
            received=len(messages),
            processed=processed,
            published=published,
        )
    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)
        if not same_account:
            await publisher.disconnect()
        await reader.disconnect()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except Exception as exception:
        respond(False, message=compact_error(exception))
        sys.exit(1)
