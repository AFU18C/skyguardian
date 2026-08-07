from pathlib import Path
import re

path = Path('tests/Feature/GroupChannelAlertPublicationTest.php')
text = path.read_text()
text = text.replace(
    "use App\\Models\\GroupChannelAlertEvent;\n",
    "use App\\Models\\GroupChannelAlertCard;\nuse App\\Models\\GroupChannelAlertEvent;\n",
    1,
)
text = text.replace(
    "use App\\Services\\GroupChannelAlertPublicationService;\n",
    "use App\\Services\\GroupChannelAlertPublicationService;\nuse App\\Services\\GroupChannelWebhookService;\n",
    1,
)

old = '''        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');
            $activePart = strstr($text, '🔻 Відбій під час цієї тривоги:', true);

            return str_ends_with($request->url(), '/sendMessage')
                && is_string($activePart)
                && str_contains($activePart, 'Купʼянський район')
                && str_contains($activePart, 'Чугуївський район')
                && ! str_contains($activePart, 'м. Харків та тергромада')
                && str_contains($text, '🔻 Відбій під час цієї тривоги:')
                && str_contains($text, 'м. Харків та тергромада')
                && str_contains($text, '🔄 Оновлено:');
        });
'''
new = '''        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'Купʼянський район')
                && str_contains($text, 'Чугуївський район')
                && ! str_contains($text, 'м. Харків та тергромада')
                && str_contains($text, '🔻 Відбій під час цієї тривоги — 1 територія')
                && str_contains($text, '🔄 Оновлено:')
                && data_get($request['reply_markup'] ?? [], 'inline_keyboard.0.0.text') === 'Показати історію ▾';
        });
'''
assert old in text
text = text.replace(old, new, 1)

pattern = re.compile(
    r'''    public function test_partial_clear_history_is_spoilered_in_red_card_and_green_clear_posts_remain\(\): void\n    \{.*?\n    \}\n\n    public function test_unchanged_snapshot_does_not_republish_active_card\(\): void''',
    re.S,
)
replacement = r'''    public function test_partial_clear_history_is_compact_toggleable_and_green_clear_posts_remain(): void
    {
        $nextMessageId = 900;
        Http::fake(function (Request $request) use (&$nextMessageId) {
            if (str_ends_with($request->url(), '/deleteMessage')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            if (str_ends_with($request->url(), '/answerCallbackQuery')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response([
                'ok' => true,
                'result' => ['message_id' => ++$nextMessageId],
            ]);
        });

        $bot = $this->alertBot();
        $service = app(GroupChannelAlertPublicationService::class);
        $service->processSnapshot($bot, []);

        $service->processSnapshot($bot->fresh(), [
            $this->alert(1401, 'Бориспільський район', 'raion', 5101, 'air_raid', 14),
            $this->alert(1402, 'Броварський район', 'raion', 5102, 'air_raid', 14),
            $this->alert(1403, 'Бучанський район', 'raion', 5103, 'air_raid', 14),
        ]);

        $beforePartial = count(Http::recorded());
        $service->processSnapshot($bot->fresh(), [
            $this->alert(1401, 'Бориспільський район', 'raion', 5101, 'air_raid', 14),
            $this->alert(1402, 'Броварський район', 'raion', 5102, 'air_raid', 14),
        ]);

        $partialRequests = collect(Http::recorded())
            ->slice($beforePartial)
            ->map(fn (array $record): Request => $record[0]);
        $green = $partialRequests->first(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'ВІДБІЙ ТРИВОГИ'));
        $red = $partialRequests->first(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'СТАТУС: АКТИВНА'));

        $this->assertNotNull($green);
        $this->assertNotNull($red);
        $this->assertStringContainsString('Бучанський район', (string) $green['text']);
        $this->assertStringContainsString('🔻 Відбій під час цієї тривоги — 1 територія', (string) $red['text']);
        $this->assertStringNotContainsString('Бучанський район', (string) $red['text']);
        $this->assertFalse(isset($red['entities']));
        $this->assertSame('Показати історію ▾', data_get($red['reply_markup'] ?? [], 'inline_keyboard.0.0.text'));
        $this->assertStringStartsWith('sg_ah:14:air_raid:', (string) data_get(
            $red['reply_markup'] ?? [],
            'inline_keyboard.0.0.callback_data',
        ));
        $this->assertSame(
            GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_TEXT,
            data_get($red['reply_markup'] ?? [], 'inline_keyboard.1.0.text'),
        );

        $card = GroupChannelAlertCard::query()
            ->where('group_channel_bot_id', $bot->id)
            ->where('scope_region_uid', '14')
            ->where('alert_type', 'air_raid')
            ->firstOrFail();
        $showData = (string) data_get($red['reply_markup'] ?? [], 'inline_keyboard.0.0.callback_data');
        $beforeShow = count(Http::recorded());

        app(GroupChannelWebhookService::class)->handle($bot->fresh(), [
            'callback_query' => [
                'id' => 'history-show-1',
                'from' => ['id' => 777],
                'data' => $showData,
                'message' => [
                    'message_id' => $card->telegram_message_id,
                    'date' => now()->timestamp,
                    'chat' => ['id' => $bot->chat_id],
                    'text' => (string) $red['text'],
                ],
            ],
        ]);

        $showRequests = collect(Http::recorded())
            ->slice($beforeShow)
            ->map(fn (array $record): Request => $record[0]);
        $expanded = $showRequests->first(
            fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'),
        );

        $this->assertNotNull($expanded);
        $this->assertStringContainsString('🔻 Відбій під час цієї тривоги:', (string) $expanded['text']);
        $this->assertStringContainsString('› Бучанський район — ', (string) $expanded['text']);
        $this->assertSame('Сховати історію ▴', data_get($expanded['reply_markup'] ?? [], 'inline_keyboard.0.0.text'));
        $this->assertSame(
            GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_TEXT,
            data_get($expanded['reply_markup'] ?? [], 'inline_keyboard.1.0.text'),
        );
        $this->assertTrue($showRequests->contains(
            fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
                && $request['callback_query_id'] === 'history-show-1',
        ));

        $hideData = (string) data_get($expanded['reply_markup'] ?? [], 'inline_keyboard.0.0.callback_data');
        $beforeHide = count(Http::recorded());
        app(GroupChannelWebhookService::class)->handle($bot->fresh(), [
            'callback_query' => [
                'id' => 'history-hide-1',
                'from' => ['id' => 777],
                'data' => $hideData,
                'message' => [
                    'message_id' => $card->telegram_message_id,
                    'date' => now()->timestamp,
                    'chat' => ['id' => $bot->chat_id],
                    'text' => (string) $expanded['text'],
                ],
            ],
        ]);

        $hideRequests = collect(Http::recorded())
            ->slice($beforeHide)
            ->map(fn (array $record): Request => $record[0]);
        $collapsed = $hideRequests->first(
            fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'),
        );

        $this->assertNotNull($collapsed);
        $this->assertStringContainsString('🔻 Відбій під час цієї тривоги — 1 територія', (string) $collapsed['text']);
        $this->assertStringNotContainsString('Бучанський район', (string) $collapsed['text']);
        $this->assertSame('Показати історію ▾', data_get($collapsed['reply_markup'] ?? [], 'inline_keyboard.0.0.text'));

        $beforeSecondPartial = count(Http::recorded());
        $service->processSnapshot($bot->fresh(), [
            $this->alert(1401, 'Бориспільський район', 'raion', 5101, 'air_raid', 14),
        ]);

        $secondRequests = collect(Http::recorded())
            ->slice($beforeSecondPartial)
            ->map(fn (array $record): Request => $record[0]);
        $secondGreen = $secondRequests->first(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'ВІДБІЙ ТРИВОГИ'));
        $secondRed = $secondRequests->first(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'СТАТУС: АКТИВНА'));

        $this->assertNotNull($secondGreen);
        $this->assertStringContainsString('Броварський район', (string) $secondGreen['text']);
        $this->assertNotNull($secondRed);
        $this->assertStringContainsString('🔻 Відбій під час цієї тривоги — 2 території', (string) $secondRed['text']);
        $this->assertStringContainsString('› Бориспільський район — ', (string) $secondRed['text']);
        $this->assertStringNotContainsString('Бучанський район', (string) $secondRed['text']);
        $this->assertStringNotContainsString('Броварський район', (string) $secondRed['text']);
        $this->assertSame('Показати історію ▾', data_get($secondRed['reply_markup'] ?? [], 'inline_keyboard.0.0.text'));
    }

    public function test_unchanged_snapshot_does_not_republish_active_card(): void'''
text, n = pattern.subn(replacement, text, count=1)
assert n == 1, n
path.write_text(text)

path = Path('tests/Feature/GroupChannelAlertDirectTelegramTest.php')
text = path.read_text()
marker = '''    public function test_direct_alert_service_respects_disabled_map_button_setting(): void
    {'''
insert = '''    public function test_direct_alert_service_preserves_history_toggle_when_map_button_is_disabled(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 1004],
        ]));

        $bot = $this->bot([
            'module_settings' => [
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS => [
                    'enabled' => true,
                    'map_button_enabled' => false,
                ],
            ],
        ]);

        app(DirectGroupChannelTelegramService::class)->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "🚨 ПОВІТРЯНА ТРИВОГА\\n\\n📍 Київська область",
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => 'Показати історію ▾',
                        'callback_data' => 'sg_ah:14:air_raid:1786130000:show',
                    ],
                ]],
            ],
        ]);

        $request = Http::recorded()[0][0];

        $this->assertSame('Показати історію ▾', data_get($request['reply_markup'] ?? [], 'inline_keyboard.0.0.text'));
        $this->assertCount(1, data_get($request['reply_markup'] ?? [], 'inline_keyboard', []));
    }

    public function test_direct_alert_service_respects_disabled_map_button_setting(): void
    {'''
assert marker in text
path.write_text(text.replace(marker, insert, 1))
