#!/usr/bin/env python3
import asyncio
import html
import json
import os
import re
import time
import uuid
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urlparse

from telethon import TelegramClient
from telethon.errors import AuthTokenExpiredError, FloodWaitError, SessionPasswordNeededError
from telethon.sessions import StringSession
from telethon.tl.types import MessageMediaDocument, MessageMediaPhoto

HOST = os.getenv("SKYGUARDIAN_TELETHON_HOST", "127.0.0.1")
PORT = int(os.getenv("SKYGUARDIAN_TELETHON_PORT", "8787"))
QR_TTL_SECONDS = int(os.getenv("SKYGUARDIAN_QR_TTL", "120"))
REQUEST_CACHE_TTL_SECONDS = int(os.getenv("SKYGUARDIAN_REQUEST_CACHE_TTL", "21600"))
REQUEST_CACHE_LIMIT = int(os.getenv("SKYGUARDIAN_REQUEST_CACHE_LIMIT", "1000"))

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
    wait_task: asyncio.Task[Any]


qr_flows: dict[str, QrFlow] = {}
request_tasks: dict[str, asyncio.Task[dict[str, Any]]] = {}
request_results: dict[str, tuple[float, dict[str, Any]]] = {}


def normalize_phone(value: Any) -> str:
    raw = str(value or "").strip()
    digits = re.sub(r"\D+", "", raw)

    if raw.startswith("+"):
        normalized = digits
    elif raw.startswith("00"):
        normalized = digits[2:]
    elif len(digits) == 10 and digits.startswith("0"):
        # The administration panel is used in Ukraine, so accept the familiar
        # local form and convert it to the E.164 country format Telegram needs.
        normalized = "380" + digits[1:]
    elif digits.startswith("380"):
        normalized = digits
    else:
        raise RuntimeError("Номер телефона укажите в международном формате, например +380XXXXXXXXX.")

    if not re.fullmatch(r"[1-9]\d{7,14}", normalized):
        raise RuntimeError("Номер телефона укажите в международном формате, например +380XXXXXXXXX.")

    return "+" + normalized


def public_error_message(exc: Exception) -> str:
    error_name = type(exc).__name__
    if error_name == "PhoneNumberInvalidError":
        return "Telegram отклонил номер телефона. Проверьте номер и международный формат +380XXXXXXXXX."
    if error_name == "ApiIdInvalidError":
        return "Telegram API ID или API Hash неверны. Проверьте данные с my.telegram.org."
    if error_name in {"AuthTokenInvalidError", "AuthTokenAlreadyAcceptedError"}:
        return "QR-код недействителен или уже использован. Создайте новый QR-код."
    if error_name == "AuthTokenExpiredError":
        return "Срок действия QR-кода истёк. Создайте новый QR-код."

    return str(exc)


class PartialCopyError(RuntimeError):
    def __init__(
        self,
        message: str,
        message_ids: list[int],
        stage: str,
        destination_message_ids: list[int] | None = None,
    ) -> None:
        super().__init__(message)
        self.message_ids = message_ids
        self.stage = stage
        self.destination_message_ids = destination_message_ids or []


async def search_bet_messages(
    client: TelegramClient,
    keywords: list[str],
    channels: list[str],
    freshness_hours: int = 24,
    total_limit: int = 100,
) -> dict[str, Any]:
    normalized_keywords = [str(value).strip() for value in keywords if str(value).strip()]
    if not normalized_keywords:
        raise RuntimeError("Не заданы ключевые слова для поиска ставок.")
    normalized_channels = list(dict.fromkeys(
        str(value).strip() for value in channels if str(value).strip()
    ))
    if not normalized_channels:
        raise RuntimeError("Не заданы Telegram-каналы для поиска ставок.")

    freshness_hours = min(max(int(freshness_hours), 1), 720)
    total_limit = min(max(int(total_limit), 1), 500)
    normalized_keyword_checks = [value.casefold() for value in normalized_keywords]
    per_channel_limit = max(50, min(300, total_limit))
    cutoff = datetime.now(timezone.utc) - timedelta(hours=freshness_hours)
    found: dict[str, dict[str, Any]] = {}
    channel_errors: list[dict[str, str]] = []
    channels_checked = 0

    for channel in normalized_channels:
        try:
            peer: str | int = int(channel) if re.fullmatch(r"-?\d+", channel) else channel
            entity = await client.get_entity(peer)

            async for message in client.iter_messages(entity, limit=per_channel_limit):
                if message.date and message.date < cutoff:
                    break
                message_text = str(message.message or "")
                if not any(keyword in message_text.casefold() for keyword in normalized_keyword_checks):
                    continue
                chat = await message.get_chat()
                chat_id = getattr(chat, "id", None)
                key = f"{chat_id}:{message.id}"
                username = getattr(chat, "username", None)
                found[key] = {
                    "id": int(message.id),
                    "date": message.date.isoformat() if message.date else None,
                    "text": message.message,
                    "chat_id": chat_id,
                    "chat_title": getattr(chat, "title", None) or getattr(chat, "first_name", None),
                    "chat_username": username,
                    "url": f"https://t.me/{username}/{message.id}" if username else None,
                }
                if len(found) >= total_limit:
                    break
            channels_checked += 1
        except FloodWaitError as exc:
            if exc.seconds > 60:
                raise RuntimeError(f"Telegram ограничил поиск на {exc.seconds} сек.") from exc
            await asyncio.sleep(exc.seconds)
        except Exception:
            channel_errors.append({"channel": channel, "error": "Канал недоступен для технического аккаунта."})
        if len(found) >= total_limit:
            break

    if channels_checked == 0:
        unavailable = ", ".join(item["channel"] for item in channel_errors[:10])
        raise RuntimeError(f"Не удалось открыть ни одного Telegram-канала: {unavailable}.")

    return {
        "messages": list(found.values()),
        "channels_checked": channels_checked,
        "channel_errors": channel_errors,
    }


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


def contains_blocked_keyword(messages: list[Any], settings: dict[str, Any]) -> bool:
    keywords = [
        str(keyword).strip().casefold()
        for keyword in settings.get("blocked_keywords") or []
        if str(keyword).strip()
    ]
    if not keywords:
        return False

    source_text = "\n".join(str(getattr(message, "message", None) or "") for message in messages).casefold()
    return any(keyword in source_text for keyword in keywords)


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


def sent_message_ids(result: Any) -> list[int]:
    values = result if isinstance(result, (list, tuple)) else [result]
    return [
        int(message_id)
        for item in values
        if (message_id := getattr(item, "id", None)) is not None
    ]


async def send_text(client: TelegramClient, destination_peer: Any, text: str) -> list[int]:
    if not text:
        return []
    result = await client.send_message(destination_peer, text, parse_mode="html", link_preview=False)
    return sent_message_ids(result)


async def send_text_with_retry(
    client: TelegramClient,
    destination_peer: Any,
    text: str,
    attempts: int = 3,
) -> list[int]:
    last_error: Exception | None = None
    for attempt in range(attempts):
        try:
            return await send_text(client, destination_peer, text)
        except Exception as exc:
            last_error = exc
            if attempt + 1 < attempts:
                await asyncio.sleep(0.25 * (attempt + 1))
    if last_error is not None:
        raise last_error
    return []


async def copy_message_group(
    client: TelegramClient,
    destination_peer: Any,
    messages: list[Any],
    settings: dict[str, Any],
) -> tuple[int, list[int]]:
    if contains_blocked_keyword(messages, settings):
        return 0, []

    text = build_html_text(messages, settings)
    message_ids = [int(message.id) for message in messages]
    resume = settings.get("resume_partial") or {}
    resume_ids = [int(value) for value in resume.get("message_ids") or []]
    resume_destination_ids = [
        int(value) for value in resume.get("destination_message_ids") or []
    ]
    if resume.get("stage") == "text_after_media" and resume_ids == message_ids:
        try:
            text_ids = await send_text_with_retry(client, destination_peer, text)
            return (len(messages), [*resume_destination_ids, *text_ids]) if text_ids else (0, resume_destination_ids)
        except Exception as exc:
            raise PartialCopyError(
                str(exc),
                message_ids,
                "text_after_media",
                resume_destination_ids,
            ) from exc

    if settings.get("copy_mode") == "text_only":
        destination_ids = await send_text(client, destination_peer, text)
        return (len(messages), destination_ids) if destination_ids else (0, [])

    media = [message.media for message in messages if has_file_media(message)]
    if not media:
        destination_ids = await send_text(client, destination_peer, text)
        return (len(messages), destination_ids) if destination_ids else (0, [])

    caption = text if text and visible_text_length(text) <= 1000 else None
    sent_media = await client.send_file(
        destination_peer,
        media if len(media) > 1 else media[0],
        caption=caption,
        parse_mode="html",
    )
    destination_ids = sent_message_ids(sent_media)
    if text and caption is None:
        try:
            destination_ids.extend(await send_text_with_retry(client, destination_peer, text))
        except Exception as exc:
            raise PartialCopyError(
                str(exc),
                message_ids,
                "text_after_media",
                destination_ids,
            ) from exc
    return len(messages), destination_ids


async def copy_message_groups(
    client: TelegramClient,
    destination_peer: Any,
    messages: list[Any],
    settings: dict[str, Any],
) -> dict[str, Any]:
    copied_count = 0
    failed: list[dict[str, Any]] = []
    last_processed_id: int | None = None
    partial_delivery: dict[str, Any] | None = None
    copied_groups: list[dict[str, Any]] = []

    for message_group in group_messages(messages):
        try:
            group_count, destination_ids = await copy_message_group(
                client,
                destination_peer,
                message_group,
                settings,
            )
            copied_count += group_count
            if destination_ids:
                copied_groups.append({
                    "source_message_ids": [int(message.id) for message in message_group],
                    "destination_message_ids": destination_ids,
                })
            last_processed_id = max(int(message.id) for message in message_group)
        except PartialCopyError as exc:
            failed.append({
                "message_ids": exc.message_ids,
                "error": str(exc)[:500],
            })
            partial_delivery = {
                "message_ids": exc.message_ids,
                "stage": exc.stage,
                "destination_message_ids": exc.destination_message_ids,
            }
            break
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
        "partial_delivery": partial_delivery,
        "copied_groups": copied_groups,
        "destination_message_ids": [
            message_id
            for group in copied_groups
            for message_id in group["destination_message_ids"]
        ],
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
        if not flow.wait_task.done():
            flow.wait_task.cancel()
            await asyncio.gather(flow.wait_task, return_exceptions=True)
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
        # The listener starts together with the QR code. Shielding keeps it
        # alive when a short HTTP poll times out, so a scan can never be lost
        # between displaying the code and pressing "check" in the admin UI.
        await asyncio.wait_for(asyncio.shield(flow.wait_task), timeout=timeout)
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
            phone = normalize_phone(phone)
            sent = await client.send_code_request(phone)
            return {
                "ok": True,
                "session": client.session.save(),
                "phone_code_hash": sent.phone_code_hash,
            }

        if action == "sign_in":
            if not phone:
                raise RuntimeError("Не указан номер телефона.")
            phone = normalize_phone(phone)
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
            qr_flows[token] = QrFlow(
                client=client,
                login=login,
                expires_at=expires_at,
                wait_task=asyncio.create_task(login.wait()),
            )
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

        if action == "search_bets":
            search_result = await search_bet_messages(
                client,
                payload.get("keywords", []),
                payload.get("channels", []),
                int(payload.get("freshness_hours") or 24),
                int(payload.get("limit") or 100),
            )
            return {
                "ok": True,
                "session": client.session.save(),
                **search_result,
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


def request_cache_key(request: dict[str, Any]) -> str | None:
    if request.get("action") != "copy_messages":
        return None

    payload = request.get("payload") or {}
    request_id = str(payload.get("request_id") or "")
    if not re.fullmatch(r"[a-f0-9]{64}", request_id):
        return None

    return f"{request.get('account_key')}:{request_id}"


def prune_request_results(now: float | None = None) -> None:
    current = time.monotonic() if now is None else now
    expired = [key for key, (expires_at, _) in request_results.items() if expires_at <= current]
    for key in expired:
        request_results.pop(key, None)

    limit = max(1, REQUEST_CACHE_LIMIT)
    while len(request_results) >= limit:
        oldest_key = min(request_results, key=lambda key: request_results[key][0])
        request_results.pop(oldest_key, None)


async def execute_request(request: dict[str, Any]) -> dict[str, Any]:
    try:
        return await process_request(request)
    except Exception as exc:
        return {"ok": False, "error": public_error_message(exc)}


def should_cache_request_result(response: dict[str, Any]) -> bool:
    if not response.get("ok"):
        return False

    partial_delivery = response.get("partial_delivery") or {}

    return (
        bool(response.get("destination_message_ids"))
        or bool(partial_delivery.get("destination_message_ids"))
        or response.get("last_processed_id") is not None
    )


async def process_request_idempotently(request: dict[str, Any]) -> dict[str, Any]:
    cache_key = request_cache_key(request)
    if cache_key is None:
        return await execute_request(request)

    now = time.monotonic()
    prune_request_results(now)
    cached = request_results.get(cache_key)
    if cached is not None:
        return cached[1]

    task = request_tasks.get(cache_key)
    if task is None:
        task = asyncio.create_task(execute_request(request))
        request_tasks[cache_key] = task

    try:
        response = await asyncio.shield(task)
    finally:
        if task.done() and not task.cancelled():
            request_tasks.pop(cache_key, None)
            result = task.result()
            if should_cache_request_result(result):
                request_results[cache_key] = (
                    time.monotonic() + max(1, REQUEST_CACHE_TTL_SECONDS),
                    result,
                )

    return response


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
        response = await process_request_idempotently(request)
    except Exception as exc:
        response = {"ok": False, "error": public_error_message(exc)}

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
