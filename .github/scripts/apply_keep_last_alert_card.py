from pathlib import Path

service_path = Path('app/Services/GroupChannelAlertPublicationService.php')
service = service_path.read_text()
old = '''        foreach ($cards as $card) {
            if ($card->telegram_message_id
                && ! $this->safeDeleteMessage($bot, $card->telegram_message_id)) {
                continue;
            }

            $card->delete();
        }
'''
new = '''        foreach ($cards as $key => $card) {
            if (! isset($changedScopes[$key])) {
                continue;
            }

            // A fully cleared oblast keeps its last red Telegram post as history.
            // Drop only the active-card tracking row so a future alert starts a new card.
            $card->delete();
        }
'''
assert old in service
service_path.write_text(service.replace(old, new, 1))

test_path = Path('tests/Feature/GroupChannelAlertPublicationTest.php')
test = test_path.read_text()
old = '''        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/deleteMessage')
                && (int) $request['message_id'] === 503;
        });
'''
new = '''        Http::assertNotSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/deleteMessage')
                && (int) $request['message_id'] === 503;
        });
'''
assert old in test
test_path.write_text(test.replace(old, new, 1))

format_test_path = Path('tests/Feature/GroupChannelAlertAllClearFormatTest.php')
format_test = format_test_path.read_text()
start = format_test.index('    public function test_last_red_card_is_retried_until_telegram_deletes_it(): void')
end = format_test.index('    private function alertBot(): GroupChannelBot', start)
replacement = '''    public function test_last_red_card_stays_in_telegram_but_is_untracked_after_full_clear(): void
    {
        $messageId = 800;
        $deleteAttempts = 0;

        Http::fake(function (Request $request) use (&$messageId, &$deleteAttempts) {
            if (str_ends_with($request->url(), '/deleteMessage')) {
                $deleteAttempts++;

                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response([
                'ok' => true,
                'result' => ['message_id' => ++$messageId],
            ]);
        });

        $bot = $this->alertBot();
        $service = app(GroupChannelAlertPublicationService::class);
        $service->processSnapshot($bot, []);
        $service->processSnapshot($bot->fresh(), [
            $this->alert('2001', 'Сумський район', '20', '2026-08-07T18:43:00+03:00'),
            $this->alert('2002', 'Шосткинський район', '20', '2026-08-07T21:19:00+03:00'),
        ]);

        $card = GroupChannelAlertCard::query()->where('group_channel_bot_id', $bot->id)->firstOrFail();
        $historicalMessageId = $card->telegram_message_id;
        $this->assertNotNull($historicalMessageId);

        $service->processSnapshot($bot->fresh(), []);

        $this->assertSame(0, $deleteAttempts);
        $this->assertDatabaseMissing('group_channel_alert_cards', ['id' => $card->id]);
        Http::assertNotSent(function (Request $request) use ($historicalMessageId): bool {
            return str_ends_with($request->url(), '/deleteMessage')
                && (int) $request['message_id'] === $historicalMessageId;
        });

        $service->processSnapshot($bot->fresh(), [
            $this->alert('2003', 'Конотопський район', '20', '2026-08-07T21:30:00+03:00'),
        ]);

        $this->assertDatabaseHas('group_channel_alert_cards', [
            'group_channel_bot_id' => $bot->id,
            'scope_region_uid' => '20',
            'alert_type' => 'air_raid',
        ]);
        $this->assertSame(0, $deleteAttempts);
        Http::assertNotSent(function (Request $request) use ($historicalMessageId): bool {
            return str_ends_with($request->url(), '/deleteMessage')
                && (int) $request['message_id'] === $historicalMessageId;
        });
    }

'''
format_test_path.write_text(format_test[:start] + replacement + format_test[end:])
