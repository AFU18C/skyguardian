#!/usr/bin/env python3
import asyncio
import json
import os
import time
import uuid
from dataclasses import dataclass
from typing import Any

from telethon import TelegramClient
from telethon.errors import AuthTokenExpiredError, SessionPasswordNeededError
from telethon.sessions import StringSession

HOST = os.getenv("SKYGUARDIAN_TELETHON_HOST", "127.0.0.1")
PORT = int(os.getenv("SKYGUARDIAN_TELETHON_PORT", "8787"))
QR_TTL_SECONDS = int(os.getenv("SKYGUARDIAN_QR_TTL", "120"))


@dataclass
class QrFlow:
    client: TelegramClient
    login: Any
    expires_at: float


qr_flows: dict[str, QrFlow] = {}


def user_payload(user: Any) -> dict[str, Any]:
    return {
        "id": user.id,
        "username": user.username,
        "first_name": user.first_name,
        "last_name": user.last_name,
        "phone": user.phone,
    }


def entity_payload(entity: Any) -> dict[str, Any]:
    return {
        "id": getattr(entity, "id", None),
        "username": getattr(entity, "username", None),
        "title": getattr(entity, "title", None),
        "first_name": getattr(entity, "first_name", None),
        "last_name": getattr(entity, "last_name", None),
    }


async def build_client(request: dict[str, Any]) -> TelegramClient:
    api_id = int(request["api_id"])
    api_hash = str(request["api_hash"])
    session = request.get("session") or ""
    client = TelegramClient(StringSession(session), api_id, api_hash)
    await client.connect()
    return client


async def require_authorized(client: TelegramClient) -> None:
    if not await client.is_user_authorized():
        raise RuntimeError("Технический аккаунт не авторизован в Telegram.")


async def process_request(request: dict[str, Any]) -> dict[str, Any]:
    action = request.get("action")
    phone = request.get("phone")
    payload = request.get("payload") or {}

    if action == "qr_wait":
        token = str(payload.get("token", ""))
        flow = qr_flows.get(token)
        if flow is None:
            raise RuntimeError("QR-сессия не найдена или уже завершена.")
        if flow.expires_at <= time.time():
            await flow.client.disconnect()
            qr_flows.pop(token, None)
            return {"ok": True, "status": "expired"}

        try:
            timeout = min(max(int(payload.get("timeout", 20)), 1), 45)
            await asyncio.wait_for(flow.login.wait(), timeout=timeout)
        except asyncio.TimeoutError:
            return {"ok": True, "status": "pending"}
        except AuthTokenExpiredError:
            await flow.client.disconnect()
            qr_flows.pop(token, None)
            return {"ok": True, "status": "expired"}

        me = await flow.client.get_me()
        response = {
            "ok": True,
            "status": "connected",
            "session": flow.client.session.save(),
            "user": user_payload(me),
        }
        await flow.client.disconnect()
        qr_flows.pop(token, None)
        return response

    client = await build_client(request)
    keep_client = False

    try:
        if action == "check":
            await require_authorized(client)
            me = await client.get_me()
            return {
                "ok": True,
                "session": client.session.save(),
                "user": user_payload(me),
            }

        if action == "send_code":
            if not phone:
                raise RuntimeError("Не указан номер телефона.")
            sent = await client.send_code_request(phone)
            return {
                "ok": True,
                "session": client.session.save(),
                "phone_code_hash": sent.phone_code_hash,
            }

        if action == "sign_in":
            if not phone:
                raise RuntimeError("Не указан номер телефона.")
            try:
                await client.sign_in(
                    phone=phone,
                    code=str(payload.get("code", "")),
                    phone_code_hash=payload.get("phone_code_hash"),
                )
            except SessionPasswordNeededError:
                return {
                    "ok": True,
                    "requires_password": True,
                    "session": client.session.save(),
                }
            me = await client.get_me()
            return {
                "ok": True,
                "requires_password": False,
                "session": client.session.save(),
                "user": user_payload(me),
            }

        if action == "sign_in_password":
            await client.sign_in(password=str(payload.get("password", "")))
            me = await client.get_me()
            return {
                "ok": True,
                "session": client.session.save(),
                "user": user_payload(me),
            }

        if action == "qr_start":
            login = await client.qr_login()
            token = str(uuid.uuid4())
            expires_at = min(login.expires.timestamp(), time.time() + QR_TTL_SECONDS)
            qr_flows[token] = QrFlow(client=client, login=login, expires_at=expires_at)
            keep_client = True
            return {
                "ok": True,
                "token": token,
                "url": login.url,
                "expires_at": int(expires_at),
                "session": client.session.save(),
            }

        await require_authorized(client)

        if action == "check_peer":
            peer = payload.get("peer")
            if not peer:
                raise RuntimeError("Не указан Telegram-источник.")
            entity = await client.get_entity(peer)
            return {
                "ok": True,
                "session": client.session.save(),
                "peer": entity_payload(entity),
            }

        if action == "fetch_messages":
            peer = payload.get("peer")
            if not peer:
                raise RuntimeError("Не указан Telegram-источник.")
            min_id = max(int(payload.get("min_id") or 0), 0)
            limit = min(max(int(payload.get("limit") or 100), 1), 100)
            messages: list[dict[str, Any]] = []
            async for message in client.iter_messages(peer, min_id=min_id, reverse=True, limit=limit):
                messages.append({
                    "id": message.id,
                    "date": message.date.isoformat() if message.date else None,
                    "text": message.message,
                    "grouped_id": message.grouped_id,
                })
            return {
                "ok": True,
                "session": client.session.save(),
                "messages": messages,
            }

        if action == "relay_messages":
            source_peer = payload.get("source_peer")
            destination_peer = payload.get("destination_peer")
            message_ids = [int(value) for value in payload.get("message_ids", [])]
            if not source_peer or not destination_peer or not message_ids:
                raise RuntimeError("Недостаточно данных для пересылки сообщений.")
            await client.forward_messages(destination_peer, message_ids, source_peer)
            return {
                "ok": True,
                "session": client.session.save(),
                "forwarded_ids": message_ids,
            }

        raise RuntimeError(f"Неизвестное действие: {action}")
    finally:
        if not keep_client:
            await client.disconnect()


async def cleanup_qr_flows() -> None:
    while True:
        await asyncio.sleep(15)
        now = time.time()
        expired = [token for token, flow in qr_flows.items() if flow.expires_at <= now]
        for token in expired:
            flow = qr_flows.pop(token, None)
            if flow is not None:
                await flow.client.disconnect()


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
    asyncio.create_task(cleanup_qr_flows())
    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
