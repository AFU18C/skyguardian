import asyncio
import importlib.util
import sys
import unittest
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


if __name__ == "__main__":
    unittest.main()
