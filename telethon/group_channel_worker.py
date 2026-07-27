#!/usr/bin/env python3
import asyncio
import json
import os
from datetime import datetime, timedelta, timezone
from typing import Any, AsyncIterator

from telethon import TelegramClient
from telethon.errors import FloodWaitError
from telethon.sessions import StringSession

HOST = os.getenv("SKYGUARDIAN_GROUP_CHANNEL_TELETHON_HOST", "127.0.0.1")
PORT = int(os.getenv("SKYGUARDIAN_GROUP_CHANNEL_TELETHON_PORT", "8788"))
DELETE_BATCH_SIZE = 100


def parse_datetime(value: Any) -> datetime | None:
    if not value:
        return None

    parsed = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)

    return parsed.astimezone(timezone.utc)


async def build_client(request: dict[str, Any]) -> TelegramClient:
    client = TelegramClient(
        StringSession(str(request.get("session") or "")),
        int(request["api_id"]),
        str(request["api_hash"]),
    )
    await client.connect()

    if not await client.is_user_authorized():
        await client.disconnect()
        raise RuntimeError("Технический аккаунт не авторизован в Telegram.")

    return client


async def selected_messages(
    client: TelegramClient,
    entity: Any,
    payload: dict[str, Any],
) -> AsyncIterator[Any]:
    mode = str(payload.get("mode") or "period")
    date_from = parse_datetime(payload.get("date_from"))
    date_to = parse_datetime(payload.get("date_to"))

    if mode == "last":
        limit = min(max(int(payload.get("count") or 1), 1), 10000)
        async for message in client.iter_messages(entity, limit=limit):
            yield message
        return

    offset_date = date_to + timedelta(seconds=1) if date_to else None
    async for message in client.iter_messages(entity, offset_date=offset_date):
        message_date = message.date
        if message_date is None:
            continue
        if message_date.tzinfo is None:
            message_date = message_date.replace(tzinfo=timezone.utc)
        else:
            message_date = message_date.astimezone(timezone.utc)

        if date_to and message_date > date_to:
            continue
        if date_from and message_date < date_from:
            break

        yield message


async def count_messages(client: TelegramClient, payload: dict[str, Any]) -> dict[str, Any]:
    peer = payload.get("peer")
    if not peer:
        raise RuntimeError("Не указан канал или группа.")

    entity = await client.get_entity(peer)
    count = 0
    first_date = None
    last_date = None

    async for message in selected_messages(client, entity, payload):
        count += 1
        value = message.date.isoformat() if message.date else None
        if first_date is None:
            first_date = value
        last_date = value

    return {
        "ok": True,
        "count": count,
        "newest_date": first_date,
        "oldest_date": last_date,
    }


async def delete_batch(client: TelegramClient, entity: Any, message_ids: list[int]) -> int:
    while True:
        try:
            await client.delete_messages(entity, message_ids, revoke=True)
            return len(message_ids)
        except FloodWaitError as error:
            await asyncio.sleep(max(int(error.seconds), 1))


async def delete_messages(client: TelegramClient, payload: dict[str, Any]) -> dict[str, Any]:
    peer = payload.get("peer")
    if not peer:
        raise RuntimeError("Не указан канал или группа.")

    entity = await client.get_entity(peer)
    batch: list[int] = []
    matched_count = 0
    deleted_count = 0

    async for message in selected_messages(client, entity, payload):
        matched_count += 1
        batch.append(int(message.id))

        if len(batch) >= DELETE_BATCH_SIZE:
            deleted_count += await delete_batch(client, entity, batch)
            batch = []

    if batch:
        deleted_count += await delete_batch(client, entity, batch)

    return {
        "ok": True,
        "matched_count": matched_count,
        "deleted_count": deleted_count,
        "failed_count": max(matched_count - deleted_count, 0),
    }


async def process_request(request: dict[str, Any]) -> dict[str, Any]:
    action = str(request.get("action") or "")
    payload = request.get("payload") or {}
    client = await build_client(request)

    try:
        if action == "group_channel_bulk_count":
            return await count_messages(client, payload)
        if action == "group_channel_bulk_delete":
            return await delete_messages(client, payload)

        raise RuntimeError(f"Неизвестное действие модуля групп и каналов: {action}")
    finally:
        await client.disconnect()


async def handle_connection(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    try:
        raw = await reader.readline()
        if not raw:
            return
        request = json.loads(raw.decode("utf-8"))
        response = await process_request(request)
    except Exception as exc:
        response = {"ok": False, "error": str(exc)}

    writer.write((json.dumps(response, ensure_ascii=False) + "\n").encode("utf-8"))
    await writer.drain()
    writer.close()
    await writer.wait_closed()


async def main() -> None:
    server = await asyncio.start_server(handle_connection, HOST, PORT)
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
