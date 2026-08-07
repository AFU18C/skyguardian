<?php

namespace Tests\Feature;

use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelAlertState;
use App\Models\GroupChannelBot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertRichHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_history_shows_active_summary_timeline_durations_refresh_and_map(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07T18:00:00Z'));
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 7001],
        ]));

        $bot = $this->bot('-1001111111111', 'target_alerts');
        $cycle = CarbonImmutable::parse('2026-08-07T17:00:00Z');

        $this->endedEpisode(
            $bot,
            '1403',
            'Київська область — Бучанський район',
            $cycle,
            CarbonImmutable::parse('2026-08-07T17:20:00Z'),
            'active-history-ended',
        );
        GroupChannelAlertState::query()->create([
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '1404',
            'scope_region_uid' => '14',
            'region_name' => 'Київська область — Обухівський район',
            'alert_type' => 'air_raid',
            'started_at' => CarbonImmutable::parse('2026-08-07T17:05:00Z'),
            'last_seen_at' => CarbonImmutable::now('UTC'),
        ]);

        $this->postStart($bot, 777, $cycle);

        Http::assertSent(function (Request $request) use ($bot, $cycle): bool {
            $text = (string) ($request['text'] ?? '');
            $keyboard = (array) data_get($request->data(), 'reply_markup.inline_keyboard', []);
            $flatButtons = collect($keyboard)->flatten(1);

            return str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === '777'
                && str_contains($text, '📊 ІСТОРІЯ: ПОВІТРЯНА ТРИВОГА')
                && str_contains($text, '🔴 СТАТУС: ТРИВАЄ')
                && str_contains($text, '🚨 Зараз активні: 1 територія')
                && str_contains($text, '📍 Всього було охоплено: 2 території')
                && str_contains($text, '📈 Максимально одночасно: 2 території')
                && str_contains($text, '🚨 ХРОНОЛОГІЯ')
                && str_contains($text, 'Бучанський район')
                && str_contains($text, 'Обухівський район')
                && str_contains($text, '⏱ ТРИВАЛІСТЬ ПО ТЕРИТОРІЯХ')
                && $flatButtons->contains(fn (mixed $button): bool => is_array($button)
                    && ($button['callback_data'] ?? null) === 'sg_ahr:'.$bot->id.':14:a:'.$cycle->getTimestamp())
                && $flatButtons->contains(fn (mixed $button): bool => is_array($button)
                    && ($button['url'] ?? null) === GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_URL);
        });
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));

        CarbonImmutable::setTestNow();
    }

    public function test_refresh_callback_routes_to_encoded_bot_and_sends_completed_history_only_to_user(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07T18:00:00Z'));
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 7002],
        ]));

        $bot = $this->bot('-1001111111111', 'target_alerts');
        $this->bot('-1002222222222', 'other_alerts');
        $cycle = CarbonImmutable::parse('2026-08-07T17:00:00Z');
        $endedAt = CarbonImmutable::parse('2026-08-07T17:35:00Z');
        $this->endedEpisode(
            $bot,
            '1403',
            'Київська область — Бучанський район',
            $cycle,
            $endedAt,
            'completed-history',
        );

        $response = $this
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 99101,
                'callback_query' => [
                    'id' => 'refresh-history-1',
                    'from' => [
                        'id' => 777,
                        'is_bot' => false,
                        'first_name' => 'User',
                    ],
                    'data' => 'sg_ahr:'.$bot->id.':14:a:'.$cycle->getTimestamp(),
                    'message' => [
                        'message_id' => 51,
                        'date' => CarbonImmutable::now('UTC')->getTimestamp(),
                        'chat' => [
                            'id' => 777,
                            'type' => 'private',
                        ],
                        'text' => 'old private history',
                    ],
                ],
            ]);

        $response->assertOk();
        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === '777'
                && str_contains($text, '🟢 СТАТУС: ЗАВЕРШЕНА')
                && str_contains($text, '🟢 Повний відбій:')
                && str_contains($text, '⏱ Загальна тривалість: 35 хв')
                && str_contains($text, '✅ Повний відбій о');
        });
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['callback_query_id'] ?? null) === 'refresh-history-1';
        });
        Http::assertNotSent(function (Request $request) use ($bot): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === (string) $bot->chat_id;
        });
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));

        CarbonImmutable::setTestNow();
    }

    public function test_history_stops_at_first_full_clear_and_does_not_mix_next_alert_cycle(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07T19:00:00Z'));
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 7003],
        ]));

        $bot = $this->bot('-1001111111111', 'target_alerts');
        $firstCycle = CarbonImmutable::parse('2026-08-07T17:00:00Z');
        $this->endedEpisode(
            $bot,
            '1403',
            'Київська область — Бучанський район',
            $firstCycle,
            CarbonImmutable::parse('2026-08-07T17:30:00Z'),
            'first-cycle',
        );
        $this->endedEpisode(
            $bot,
            '1405',
            'Київська область — Фастівський район',
            CarbonImmutable::parse('2026-08-07T18:00:00Z'),
            CarbonImmutable::parse('2026-08-07T18:20:00Z'),
            'second-cycle',
        );

        $this->postStart($bot, 777, $firstCycle);

        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === '777'
                && str_contains($text, 'Бучанський район')
                && ! str_contains($text, 'Фастівський район')
                && str_contains($text, '📍 Територій під час тривоги: 1 територія');
        });

        CarbonImmutable::setTestNow();
    }

    private function postStart(GroupChannelBot $bot, int $userId, CarbonImmutable $cycle): void
    {
        $payload = implode('_', [
            'ah',
            $bot->id,
            '14',
            'a',
            $cycle->getTimestamp(),
            $cycle->addHours(1)->getTimestamp(),
        ]);

        $this
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => random_int(100000, 999999),
                'message' => [
                    'message_id' => 41,
                    'date' => CarbonImmutable::now('UTC')->getTimestamp(),
                    'from' => [
                        'id' => $userId,
                        'is_bot' => false,
                        'first_name' => 'User',
                    ],
                    'chat' => [
                        'id' => $userId,
                        'type' => 'private',
                    ],
                    'text' => '/start '.$payload,
                ],
            ])
            ->assertOk();
    }

    private function endedEpisode(
        GroupChannelBot $bot,
        string $regionUid,
        string $regionName,
        CarbonImmutable $startedAt,
        CarbonImmutable $endedAt,
        string $eventKey,
    ): void {
        GroupChannelAlertEvent::query()->create([
            'group_channel_bot_id' => $bot->id,
            'event_key' => $eventKey,
            'kind' => GroupChannelAlertEvent::KIND_END,
            'region_uid' => $regionUid,
            'scope_region_uid' => '14',
            'region_name' => $regionName,
            'alert_type' => 'air_raid',
            'event_at' => $endedAt,
            'started_at' => $startedAt,
            'status' => GroupChannelAlertEvent::STATUS_SENT,
            'sent_at' => $endedAt->addSecond(),
        ]);
    }

    private function bot(string $chatId, string $groupName): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS]['enabled'] = true;
        $token = '123456:test-token';
        $secret = str_repeat('a', 48);

        return GroupChannelBot::query()->create([
            'bot_name' => 'Alert Bot',
            'bot_token' => $token,
            'token_fingerprint' => hash('sha256', $token),
            'webhook_secret' => $secret,
            'admin_id' => '100500',
            'group_name' => $groupName,
            'group_link' => 'https://t.me/'.$groupName,
            'chat_type' => 'channel',
            'chat_id' => $chatId,
            'bot_username' => 'test_alert_bot',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }
}
