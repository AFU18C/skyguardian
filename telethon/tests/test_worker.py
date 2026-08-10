import asyncio
import importlib.util
import sys
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock, patch

from telethon.tl.types import MessageMediaPhoto, MessageMediaWebPage

WORKER_PATH = Path(__file__).resolve().parents[1] / "worker.py"
WORKER_SPEC = importlib.util.spec_from_file_location("skyguardian_worker", WORKER_PATH)
if WORKER_SPEC is None or WORKER_SPEC.loader is None:
    raise RuntimeError("Cannot load Telethon worker module.")
worker = importlib.util.module_from_spec(WORKER_SPEC)
sys.modules[WORKER_SPEC.name] = worker
WORKER_SPEC.loader.exec_module(worker)


class WorkerCopyTest(unittest.TestCase):
    def test_web_preview_is_not_file_media_even_when_photo_property_exists(self) -> None:
        message = SimpleNamespace(
            media=Mock(spec=MessageMediaWebPage),
            photo=object(),
            document=None,
        )

        self.assertFalse(worker.has_file_media(message))

    def test_native_photo_is_file_media(self) -> None:
        message = SimpleNamespace(media=Mock(spec=MessageMediaPhoto))

        self.assertTrue(worker.has_file_media(message))

    def test_web_preview_is_copied_as_clean_text_without_link(self) -> None:
        client = SimpleNamespace(
            send_message=AsyncMock(),
            send_file=AsyncMock(),
        )
        message = SimpleNamespace(
            id=10,
            message="Текст https://example.com/page",
            media=Mock(spec=MessageMediaWebPage),
            photo=object(),
            document=None,
        )

        copied = asyncio.run(worker.copy_message_group(
            client,
            "@destination",
            [message],
            {"strip_links": True, "copy_mode": "original"},
        ))

        self.assertEqual(1, copied)
        client.send_file.assert_not_awaited()
        client.send_message.assert_awaited_once_with(
            "@destination",
            "Текст",
            parse_mode="html",
            link_preview=False,
        )

    def test_post_with_blocked_keyword_is_skipped_case_insensitively(self) -> None:
        client = SimpleNamespace(send_message=AsyncMock(), send_file=AsyncMock())
        messages = [
            SimpleNamespace(id=10, message="Лучшее КАЗИНО города", media=None, grouped_id=None),
            SimpleNamespace(id=11, message="Обычная новость", media=None, grouped_id=None),
        ]

        result = asyncio.run(worker.copy_message_groups(
            client,
            "@destination",
            messages,
            {"blocked_keywords": ["казино"], "copy_mode": "original"},
        ))

        self.assertEqual(1, result["copied_count"])
        self.assertEqual(11, result["last_processed_id"])
        client.send_message.assert_awaited_once_with(
            "@destination",
            "Обычная новость",
            parse_mode="html",
            link_preview=False,
        )
        client.send_file.assert_not_awaited()

    def test_disabled_blocked_keyword_filter_does_not_change_copying(self) -> None:
        client = SimpleNamespace(send_message=AsyncMock(), send_file=AsyncMock())
        message = SimpleNamespace(id=10, message="Казино", media=None)

        copied = asyncio.run(worker.copy_message_group(
            client,
            "@destination",
            [message],
            {"blocked_keywords": [], "copy_mode": "original"},
        ))

        self.assertEqual(1, copied)
        client.send_message.assert_awaited_once()

    def test_failed_group_returns_checkpoint_without_recopying_previous_groups(self) -> None:
        messages = [
            SimpleNamespace(id=10, grouped_id=None),
            SimpleNamespace(id=11, grouped_id=None),
            SimpleNamespace(id=12, grouped_id=None),
        ]

        with patch.object(
            worker,
            "copy_message_group",
            AsyncMock(side_effect=[1, RuntimeError("broken media"), 1]),
        ) as copy_group:
            result = asyncio.run(worker.copy_message_groups(
                SimpleNamespace(),
                "@destination",
                messages,
                {},
            ))

        self.assertEqual(2, copy_group.await_count)
        self.assertEqual(1, result["copied_count"])
        self.assertEqual(1, result["failed_count"])
        self.assertEqual(10, result["last_processed_id"])
        self.assertEqual([11], result["failed"][0]["message_ids"])
        self.assertEqual("broken media", result["failed"][0]["error"])

    def test_long_caption_retry_resumes_with_text_without_resending_media(self) -> None:
        message = SimpleNamespace(
            id=20,
            grouped_id=None,
            message="Длинный текст " * 100,
            media=Mock(spec=MessageMediaPhoto),
        )
        first_client = SimpleNamespace(
            send_file=AsyncMock(),
            send_message=AsyncMock(side_effect=RuntimeError("temporary text failure")),
        )

        first = asyncio.run(worker.copy_message_groups(
            first_client,
            "@destination",
            [message],
            {"copy_mode": "original"},
        ))

        self.assertEqual({"message_ids": [20], "stage": "text_after_media"}, first["partial_delivery"])
        first_client.send_file.assert_awaited_once()

        resume_client = SimpleNamespace(send_file=AsyncMock(), send_message=AsyncMock())
        second = asyncio.run(worker.copy_message_groups(
            resume_client,
            "@destination",
            [message],
            {
                "copy_mode": "original",
                "resume_partial": first["partial_delivery"],
            },
        ))

        self.assertEqual(20, second["last_processed_id"])
        self.assertIsNone(second["partial_delivery"])
        resume_client.send_file.assert_not_awaited()
        resume_client.send_message.assert_awaited_once()


class WorkerBetSearchTest(unittest.TestCase):
    def test_search_deduplicates_messages_and_ignores_stale_results(self) -> None:
        chat = SimpleNamespace(id=77, username="bets", title="Bets")
        recent = SimpleNamespace(
            id=10,
            date=datetime.now(timezone.utc) - timedelta(hours=1),
            message="Реал — Барселона, П1",
            get_chat=AsyncMock(return_value=chat),
        )
        stale = SimpleNamespace(
            id=11,
            date=datetime.now(timezone.utc) - timedelta(hours=30),
            message="Старый прогноз",
            get_chat=AsyncMock(return_value=chat),
        )

        async def iter_messages(*_args, **_kwargs):
            yield recent
            yield stale

        client = SimpleNamespace(iter_messages=iter_messages)
        result = asyncio.run(worker.search_bet_messages(
            client,
            ["П1", "П1"],
            freshness_hours=24,
            total_limit=10,
        ))

        self.assertEqual(1, len(result))
        self.assertEqual(10, result[0]["id"])
        self.assertEqual("https://t.me/bets/10", result[0]["url"])

    def test_search_requires_keywords(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "ключевые слова"):
            asyncio.run(worker.search_bet_messages(SimpleNamespace(), []))


if __name__ == "__main__":
    unittest.main()
