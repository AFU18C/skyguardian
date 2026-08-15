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
            send_message=AsyncMock(return_value=SimpleNamespace(id=110)),
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

        self.assertEqual((1, [110]), copied)
        client.send_file.assert_not_awaited()
        client.send_message.assert_awaited_once_with(
            "@destination",
            "Текст",
            parse_mode="html",
            link_preview=False,
        )

    def test_post_with_blocked_keyword_is_skipped_case_insensitively(self) -> None:
        client = SimpleNamespace(
            send_message=AsyncMock(return_value=SimpleNamespace(id=111)),
            send_file=AsyncMock(),
        )
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
        self.assertEqual([111], result["destination_message_ids"])
        self.assertEqual([11], result["copied_groups"][0]["source_message_ids"])
        client.send_message.assert_awaited_once_with(
            "@destination",
            "Обычная новость",
            parse_mode="html",
            link_preview=False,
        )
        client.send_file.assert_not_awaited()

    def test_disabled_blocked_keyword_filter_does_not_change_copying(self) -> None:
        client = SimpleNamespace(
            send_message=AsyncMock(return_value=SimpleNamespace(id=112)),
            send_file=AsyncMock(),
        )
        message = SimpleNamespace(id=10, message="Казино", media=None)

        copied = asyncio.run(worker.copy_message_group(
            client,
            "@destination",
            [message],
            {"blocked_keywords": [], "copy_mode": "original"},
        ))

        self.assertEqual((1, [112]), copied)
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
            AsyncMock(side_effect=[(1, [210]), RuntimeError("broken media"), (1, [212])]),
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
        self.assertEqual([210], result["destination_message_ids"])
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
            send_file=AsyncMock(return_value=SimpleNamespace(id=220)),
            send_message=AsyncMock(side_effect=RuntimeError("temporary text failure")),
        )

        first = asyncio.run(worker.copy_message_groups(
            first_client,
            "@destination",
            [message],
            {"copy_mode": "original"},
        ))

        self.assertEqual({
            "message_ids": [20],
            "stage": "text_after_media",
            "destination_message_ids": [220],
        }, first["partial_delivery"])
        first_client.send_file.assert_awaited_once()

        resume_client = SimpleNamespace(
            send_file=AsyncMock(),
            send_message=AsyncMock(return_value=SimpleNamespace(id=221)),
        )
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
        self.assertEqual([220, 221], second["destination_message_ids"])
        resume_client.send_file.assert_not_awaited()
        resume_client.send_message.assert_awaited_once()


class WorkerAuthenticationTest(unittest.TestCase):
    def tearDown(self) -> None:
        worker.qr_flows.clear()

    def test_phone_is_normalized_for_telegram(self) -> None:
        self.assertEqual("+380986414076", worker.normalize_phone("098 641-40-76"))
        self.assertEqual("+380986414076", worker.normalize_phone("+380 (98) 641-40-76"))
        self.assertEqual("+380986414076", worker.normalize_phone("00380986414076"))

    def test_invalid_local_phone_is_rejected_before_telegram_request(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "международном формате"):
            worker.normalize_phone("986414076")

    def test_telegram_auth_errors_are_explained(self) -> None:
        phone_error = type("PhoneNumberInvalidError", (Exception,), {})()
        api_error = type("ApiIdInvalidError", (Exception,), {})()

        self.assertIn("отклонил номер", worker.public_error_message(phone_error))
        self.assertIn("API ID", worker.public_error_message(api_error))

    def test_qr_confirmation_is_not_lost_before_first_poll(self) -> None:
        async def scenario() -> None:
            client = SimpleNamespace(
                get_me=AsyncMock(return_value=SimpleNamespace(
                    id=77,
                    username="guardian",
                    first_name="Sky",
                    last_name="Guardian",
                    phone="380986414076",
                )),
                disconnect=AsyncMock(),
                session=SimpleNamespace(save=Mock(return_value="saved-session")),
            )

            async def accepted_before_poll():
                return True

            task = asyncio.create_task(accepted_before_poll())
            await task
            worker.qr_flows["accepted"] = worker.QrFlow(
                client=client,
                login=SimpleNamespace(),
                expires_at=(datetime.now(timezone.utc) + timedelta(minutes=1)).timestamp(),
                wait_task=task,
            )

            result = await worker.process_qr_wait({"token": "accepted", "timeout": 1})

            self.assertEqual("connected", result["status"])
            self.assertEqual(77, result["user"]["id"])
            self.assertEqual("saved-session", result["session"])
            client.disconnect.assert_awaited_once()

        asyncio.run(scenario())


class WorkerIdempotencyTest(unittest.TestCase):
    def tearDown(self) -> None:
        worker.request_tasks.clear()
        worker.request_results.clear()

    def test_copy_request_result_is_reused_after_the_caller_loses_the_response(self) -> None:
        request = {
            "action": "copy_messages",
            "account_key": "17",
            "payload": {"request_id": "a" * 64},
        }

        async def scenario() -> None:
            with patch.object(
                worker,
                "process_request",
                AsyncMock(return_value={"ok": True, "destination_message_ids": [901]}),
            ) as process:
                first = await worker.process_request_idempotently(request)
                second = await worker.process_request_idempotently(request)

                self.assertEqual(first, second)
                self.assertEqual(1, process.await_count)

        asyncio.run(scenario())

    def test_concurrent_copy_requests_share_one_in_flight_operation(self) -> None:
        request = {
            "action": "copy_messages",
            "account_key": "18",
            "payload": {"request_id": "b" * 64},
        }

        async def delayed_result(_request: dict[str, object]) -> dict[str, object]:
            await asyncio.sleep(0)
            return {"ok": True, "destination_message_ids": [902]}

        async def scenario() -> None:
            with patch.object(
                worker,
                "process_request",
                AsyncMock(side_effect=delayed_result),
            ) as process:
                first, second = await asyncio.gather(
                    worker.process_request_idempotently(request),
                    worker.process_request_idempotently(request),
                )

                self.assertEqual(first, second)
                self.assertEqual(1, process.await_count)

        asyncio.run(scenario())

    def test_failed_request_without_delivery_checkpoint_is_not_cached(self) -> None:
        request = {
            "action": "copy_messages",
            "account_key": "19",
            "payload": {"request_id": "c" * 64},
        }

        async def scenario() -> None:
            with patch.object(
                worker,
                "process_request",
                AsyncMock(return_value={"ok": False, "error": "temporary"}),
            ) as process:
                await worker.process_request_idempotently(request)
                await worker.process_request_idempotently(request)

                self.assertEqual(2, process.await_count)

        asyncio.run(scenario())

    def test_partial_media_delivery_is_cached_before_text_resume(self) -> None:
        request = {
            "action": "copy_messages",
            "account_key": "17",
            "payload": {"request_id": "d" * 64},
        }
        response = {
            "ok": True,
            "last_processed_id": None,
            "destination_message_ids": [],
            "partial_delivery": {
                "message_ids": [44],
                "stage": "text_after_media",
                "destination_message_ids": [944],
            },
        }

        async def scenario() -> None:
            with patch.object(
                worker,
                "process_request",
                AsyncMock(return_value=response),
            ) as process:
                first = await worker.process_request_idempotently(request)
                second = await worker.process_request_idempotently(request)

                self.assertEqual(first, second)
                self.assertEqual(1, process.await_count)

        asyncio.run(scenario())


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

        client = SimpleNamespace(
            get_entity=AsyncMock(return_value=chat),
            iter_messages=iter_messages,
        )
        result = asyncio.run(worker.search_bet_messages(
            client,
            ["П1", "П1"],
            ["@bets"],
            freshness_hours=24,
            total_limit=10,
        ))

        self.assertEqual(1, len(result["messages"]))
        self.assertEqual(10, result["messages"][0]["id"])
        self.assertEqual("https://t.me/bets/10", result["messages"][0]["url"])
        self.assertEqual(1, result["channels_checked"])
        client.get_entity.assert_awaited_once_with("@bets")

    def test_search_requires_keywords(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "ключевые слова"):
            asyncio.run(worker.search_bet_messages(SimpleNamespace(), [], ["@bets"]))

    def test_search_requires_configured_channels(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "Telegram-каналы"):
            asyncio.run(worker.search_bet_messages(SimpleNamespace(), ["П1"], []))

    def test_search_opens_private_invite_link_for_joined_account(self) -> None:
        invite_link = "https://t.me/+PrivateInvite123"
        chat = SimpleNamespace(id=77, username=None, title="Private Bets")

        async def iter_messages(*_args, **_kwargs):
            if False:
                yield None

        client = SimpleNamespace(
            get_entity=AsyncMock(return_value=chat),
            iter_messages=iter_messages,
        )

        result = asyncio.run(worker.search_bet_messages(
            client,
            ["П1"],
            [invite_link],
            total_limit=10,
        ))

        self.assertEqual(1, result["channels_checked"])
        client.get_entity.assert_awaited_once_with(invite_link)

    def test_search_skips_unavailable_channel_and_never_uses_global_search(self) -> None:
        chat = SimpleNamespace(id=77, username="bets", title="Bets")
        message = SimpleNamespace(
            id=10,
            date=datetime.now(timezone.utc),
            message="Реал — Барселона, П1",
            get_chat=AsyncMock(return_value=chat),
        )

        async def get_entity(peer):
            if peer == "@missing":
                raise RuntimeError("not found")
            return chat

        calls = []

        async def iter_messages(entity, **kwargs):
            calls.append((entity, kwargs))
            yield message

        result = asyncio.run(worker.search_bet_messages(
            SimpleNamespace(get_entity=get_entity, iter_messages=iter_messages),
            ["П1"],
            ["@missing", "@bets"],
            total_limit=10,
        ))

        self.assertEqual(1, result["channels_checked"])
        self.assertEqual("@missing", result["channel_errors"][0]["channel"])
        self.assertTrue(all(entity is chat for entity, _kwargs in calls))
        self.assertTrue(all("search" not in kwargs for _entity, kwargs in calls))


if __name__ == "__main__":
    unittest.main()
