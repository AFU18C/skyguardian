#!/usr/bin/env python3
import asyncio
import html
import json
import os
import re
import time
import uuid
from dataclasses import dataclass
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urlparse

from telethon import TelegramClient
from telethon.errors import AuthTokenExpiredError, SessionPasswordNeededError
from telethon.sessions import StringSession
from telethon.tl.types import MessageMediaDocument, MessageMediaPhoto

HOST = os.getenv("SKYGUARDIAN_TELETHON_HOST", "127.0.0.1")
PORT = int(os.getenv("SKYGUARDIAN_TELETHON_PORT", "8787"))
QR_TTL_SECONDS = int(os.getenv("SKYGUARDIAN_QR_TTL", "120"))

URL_PATTERN = re.compile(
    r"(?i)(?:https?://|www\.)\S+|(?:t\.me|telegram\.me)/\S+"
)
HASHTAG_PATTERN = re.compile(r"(?<!\w)#[\w_]+", re.UNICODE)
MENTION_PATTERN = re.compile(r"(?<![\w@])@[A-Za-z0-9_]{5,}")


@dataclass
class QrFlow:
    client: TelegramClient
    login: Any
    expires_at: float


qr_flows: dict[str, QrFlow] = {}


class TelegramHtmlSanitizer(HTMLParser):
    allowed_tags = {
        "b": "b",
        "strong": "b",
        "i": "i",
        "em": "i",
        "u": "u",
        "s": "s",
        "strike": "s",
        "code": "code",
        "pre": "pre",
        "blockquote": "blockquote",
        "a": "a",
        "br": "br",
    }

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        normalized = self.allowed_tags.get(tag.lower())
        if normalized is None:
            return
        if normalized == "br":
            self.parts.append("\n")
            return
        if normalized == "a":
            href = next((value for name, value in attrs if name.lower() == "href"), None)
            if href and safe_link(href):
                self.parts.append(f'<a href="{html.escape(href, quote=True)}">')
            else:
                self.parts.append("<a>")
            return
        self.parts.append(f"<{normalized}>")

    def handle_endtag(self, tag: str) -> None:
        normalized = self.allowed_tags.get(tag.lower())
        if normalized and normalized != "br":
            self.parts.append(f"</{normalized}>")

    def handle_data(self, data: str) -> None:
        self.parts.append(html.escape(data))

    def value(self) -> str:
        return "".join(self.parts).strip()


def safe_link(value: str) -> bool:
    parsed = urlparse(value.strip())
    return parsed.scheme.lower() in {"http", "https", "tg", "mailto"}


def sanitize_footer_html(value: Any) -> str:
    if not value:
        return ""
    parser = TelegramHtmlSanitizer()
    parser.feed(str(value))
    parser.close()
    return parser.value()


def cleaned_source_text(value: Any, settings: dict[str, Any]) -> str:
    text = str(value or "")
    if settings.get("strip_links"):
        text = URL_PATTERN.sub("", text)
    if settings.get("strip_hashtags"):
        text = HASHTAG_PATTERN.sub("", text)
    if settings.get("strip_mentions"):
        text = MENTION_PATTERN.sub("", text)

    for phrase in settings.get("remove_phrases") or []:
        phrase = str(phrase).strip()
        if phrase:
            text = re.sub(re.escape(phrase), "", text, flags=re.IGNORECASE)

    text = re.sub(r"[ \t]+\n", "\n", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    text = re.sub(r"[ \t]{2,}", " ", text)
    return text.strip()


def build_html_text(messages: list[Any], settings: dict[str, Any]) -> str:
    source_parts = [
        cleaned_source_text(getattr(message, "message", None), settings)
        for message in messages
    ]
    source_text = "\n\n".join(part for part in source_parts if part)
    footer = sanitize_footer_html(settings.get("footer_html"))
    parts: list[str] = []
    if source_text:
        parts.append(html.escape(source_text))
    if footer:
        parts.append(footer)
    return "\n\n".join(parts).strip()


def visible_text_length(value: str) -> int:
    return len(html.unescape(re.sub(r"<[^>]+>", "", value)))


def has_file_media(message: Any) -> bool:
    # ``Message.photo`` can also expose the thumbnail of a web-page preview.
    # Only native Telegram photo/document media may be passed to send_file().
    return isinstance(
        getattr(message, "media", None),
        (MessageMediaPhoto, MessageMediaDocument),
    )


def group_messages(messages: list[Any]) -> list[list[Any]]:
    grouped: list[list[Any]] = []
    positions: dict[int, int] = {}
    for message in sorted((item for item in messages if item is not None), key=lambda item: item.id):
        grouped_id = getattr(message, "grouped_id", None)
        if grouped_id:
            key = int(grouped_id)
            if key in positions:
                grouped[positions[key]].append(message)
            else:
                positions[key] = len(grouped)
                grouped.append([message])
        else:
            grouped.append([message])
    return grouped


async def send_text(client: TelegramClient, destination_peer: Any, text: str) -> bool:
    if not text:
        return False
    await client.send_message(destination_peer, text, parse_mode="html", link_preview=False)
    return True


async def copy_message_group(
    client: TelegramClient,
    destination_peer: Any,
    messages: list[Any],
    settings: dict[str, Any],
) -> int:
    text = build_html_text(messages, settings)
    if settings.get("copy_mode") == "text_only":
        return len(messages) if await send_text(client, destination_peer, text) else 0

    media = [message.media for message in messages if has_file_media(message)]
    if not media:
        return len(messages) if await send_text(client, destination_peer, text) else 0

    caption = text if text and visible_text_length(text) <= 1000 else None
    await client.send_file(
        destination_peer,
        media if len(media) > 1 else media[0],
        caption=caption,
        parse_mode="html",
    )
    if text and caption is None:
        await send_text(client, destination_peer, text)
    return len(messages)


async def copy_message_groups(
    client: TelegramClient,
    destination_peer: Any,
    messages: list[Any],
    settings: dict[str, Any],
) -> dict[str, Any]:
    copied_count = 0
    failed: list[dict[str, Any]] = []
    last_processed_id: int | None = None

    for message_group in group_messages(messages):
        try:
            copied_count += await copy_message_group(
                client,
                destination_peer,
                message_group,
                settings,
            )
            last_processed_id = max(int(message.id) for message in message_group)
        except Exception as exc:
            failed.append({
                "message_ids": [int(message.id) for message in message_group],
                "error": str(exc)[:500],
            })
            # Return the successful checkpoint. The next scheduled run starts
            # with this failed group, without re-sending earlier groups.
            break

    return {
        "copied_count": copied_count,
        "failed_count": sum(len(item["message_ids"]) for item in failed),
        "failed": failed,
        "last_processed_id": last_processed_id,
    }


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


async def finish_qr_flow(token: str) -> None:
    flow = qr_flows.pop(token, None)
    if flow is not None:
        await flow.client.disconnect()


async def process_qr_wait(payload: dict[str, Any]) -> dict[str, Any]:
    token = str(payload.get("token", ""))
    flow = qr_flows.get(token)

    if flow is None:
        raise RuntimeError("QR-сессия не найдена или уже завершена.")

    if flow.expires_at <= time.time():
        await finish_qr_flow(token)
        return {"ok": True, "status": "expired"}

    try:
        timeout = min(max(int(payload.get("timeout", 20)), 1), 45)
        await asyncio.wait_for(flow.login.wait(), timeout=timeout)
    except asyncio.TimeoutError:
        return {"ok": True, "status": "pending"}
    except AuthTokenExpiredError:
        await finish_qr_flow(token)
        return {"ok": True, "status": "expired"}
    except SessionPasswordNeededError:
        session = flow.client.session.save()
        await finish_qr_flow(token)
        return {
            "ok": True,
            "status": "awaiting_password",
            "session": session,
        }

    me = await flow.client.get_me()
    response = {
        "ok": True,
        "status": "connected",
        "session": flow.client.session.save(),
        "user": user_payload(me),
    }
    await finish_qr_flow(token)
    return response


async def process_request(request: dict[str, Any]) -> dict[str, Any]:
    action = request.get("action")
    phone = request.get("phone")
    payload = request.get("payload") or {}

    if action == "qr_wait":
        return await process_qr_wait(payload)

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

        if action == "latest_message_id":
            peer = payload.get("peer")
            if not peer:
                raise RuntimeError("Не указан Telegram-источник.")
            messages = await client.get_messages(peer, limit=1)
            latest_id = int(messages[0].id) if messages else 0
            return {
                "ok": True,
                "session": client.session.save(),
                "latest_message_id": latest_id,
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

        if action == "copy_messages":
            source_peer = payload.get("source_peer")
            destination_peer = payload.get("destination_peer")
            message_ids = sorted({int(value) for value in payload.get("message_ids", [])})
            settings = payload.get("settings") or {}
            if not source_peer or not destination_peer or not message_ids:
                raise RuntimeError("Недостаточно данных для копирования сообщений.")

            messages = await client.get_messages(source_peer, ids=message_ids)
            copy_result = await copy_message_groups(
                client,
                destination_peer,
                list(messages),
                settings,
            )
            return {
                "ok": True,
                "session": client.session.save(),
                **copy_result,
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
            await finish_qr_flow(token)


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
    cleanup_task = asyncio.create_task(cleanup_qr_flows())
    try:
        async with server:
            await server.serve_forever()
    finally:
        cleanup_task.cancel()
        await asyncio.gather(cleanup_task, return_exceptions=True)


if __name__ == "__main__":
    asyncio.run(main())
