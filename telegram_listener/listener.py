import asyncio
import logging
import os
from pathlib import Path

from dotenv import load_dotenv
from telethon import TelegramClient, events
from telethon.errors import FloodWaitError, RPCError

from processor import process_message

BASE_DIR = Path(__file__).resolve().parent
load_dotenv(BASE_DIR / ".env")

logging.basicConfig(
    level=os.getenv("LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
logger = logging.getLogger("skyguardian.telegram_listener")


def required_env(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value


API_ID = int(required_env("TELEGRAM_API_ID"))
API_HASH = required_env("TELEGRAM_API_HASH")
PHONE = required_env("TELEGRAM_PHONE")
SOURCE_CHAT = required_env("SOURCE_CHAT")
TARGET_CHAT = required_env("TARGET_CHAT")
MESSAGE_PREFIX = os.getenv("MESSAGE_PREFIX", "")
SESSION_PATH = BASE_DIR / os.getenv("TELEGRAM_SESSION", "storage/skyguardian")
SESSION_PATH.parent.mkdir(parents=True, exist_ok=True)

client = TelegramClient(str(SESSION_PATH), API_ID, API_HASH)


@client.on(events.NewMessage(chats=SOURCE_CHAT))
async def relay_message(event: events.NewMessage.Event) -> None:
    prepared = process_message(event.raw_text or "", MESSAGE_PREFIX)
    if prepared is None:
        logger.info("Skipped empty message %s", event.id)
        return

    try:
        await client.send_message(TARGET_CHAT, prepared, link_preview=False)
        logger.info("Relayed source message %s", event.id)
    except FloodWaitError as exc:
        logger.warning("Telegram flood wait: %s seconds", exc.seconds)
        await asyncio.sleep(exc.seconds)
        await client.send_message(TARGET_CHAT, prepared, link_preview=False)
    except RPCError:
        logger.exception("Telegram rejected source message %s", event.id)


async def main() -> None:
    await client.start(phone=PHONE)
    me = await client.get_me()
    logger.info("Connected as %s (%s)", me.username or me.first_name, me.id)
    logger.info("Listening to %s and publishing to %s", SOURCE_CHAT, TARGET_CHAT)
    await client.run_until_disconnected()


if __name__ == "__main__":
    asyncio.run(main())
