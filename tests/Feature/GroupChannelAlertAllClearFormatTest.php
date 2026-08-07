<?php

namespace Tests\Feature;

use App\Models\GroupChannelAlertCard;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelAlertPublicationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertAllClearFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_clear_is_one_green_post_per_oblast_with_end_times_and_durations(): void
    {
        CarbonImmutable::setTestNow('2026-08-07T18:21:00Z');

        try {
            Http::fake([
                'https://api.telegram.org/*' => Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 700],
                ]),
            ]);

            $bot = $this->alertBot();
            $service = app(GroupChannelAlertPublicationService::class);
            $service->processSnapshot($bot, []);

            $service->processSnapshot($bot->fresh(), [
                $this->alert('2001', 'Охтирський район', '20', '2026-08-07T20:50:00+03:00'),
                $this->alert('2002', 'Сумський район', '20', '2026-08-07T18:43:00+03:00'),
                $this->alert('1901', 'Лубенський район', '19', '2026-08-07T20:27:00+03:00'),
                $this->alert('1902', 'Полтавський район', '19', '2026-08-07T20:09:00+03:00'),
            ]);

            $beforeClear = count(Http::recorded());
            $service->processSnapshot($bot->fresh(), []);
            $clearRequests = collect(Http::recorded())
                ->slice($beforeClear)
                ->map(fn (array $record): Request => $record[0])
                ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))
                ->filter(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'ВІДБІЙ ТРИВОГИ'))
                ->values();

            $this->assertCount(2, $clearRequests);

            $sumy = $clearRequests->first(fn (Request $request): bool => str_contains((string) $request['text'], 'Сумська область'));
            $poltava = $clearRequests->first(fn (Request $request): bool => str_contains((string) $request['text'], 'Полтавська область'));

            $this->assertNotNull($sumy);
            $this->assertNotNull($poltava);

            $sumyText = (string) $sumy['text'];
            $this->assertStringContainsString('🟢 СТАТУС: БЕЗПЕЧНО', $sumyText);
            $this->assertStringContainsString('› Охтирський район — 21:21', $sumyText);
            $this->assertStringContainsString('› Сумський район — 21:21', $sumyText);
            $this->assertStringContainsString('🕒 Тривога тривала:', $sumyText);
            $this->assertStringContainsString('› Охтирський район — 31 хв', $sumyText);
            $this->assertStringContainsString('› Сумський район — 2 год 38 хв', $sumyText);
            $this->assertStringNotContainsString('Полтавська область', $sumyText);

            $poltavaText = (string) $poltava['text'];
            $this->assertStringContainsString('› Лубенський район — 21:21', $poltavaText);
            $this->assertStringContainsString('› Полтавський район — 21:21', $poltavaText);
            $this->assertStringContainsString('› Лубенський район — 54 хв', $poltavaText);
            $this->assertStringContainsString('› Полтавський район — 1 год 12 хв', $poltavaText);
            $this->assertStringNotContainsString('Сумська область', $poltavaText);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_last_red_card_is_retried_until_telegram_deletes_it(): void
    {
        $messageId = 800;
        $deleteAttempts = 0;

        Http::fake(function (Request $request) use (&$messageId, &$deleteAttempts) {
            if (str_ends_with($request->url(), '/deleteMessage')) {
                $deleteAttempts++;

                if ($deleteAttempts === 1) {
                    return Http::response(['ok' => false, 'description' => 'temporary delete failure'], 500);
                }

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
        $this->assertNotNull($card->telegram_message_id);

        $service->processSnapshot($bot->fresh(), []);

        $this->assertSame(1, $deleteAttempts);
        $this->assertDatabaseHas('group_channel_alert_cards', [
            'id' => $card->id,
            'telegram_message_id' => $card->telegram_message_id,
        ]);

        $service->processSnapshot($bot->fresh(), []);

        $this->assertSame(2, $deleteAttempts);
        $this->assertDatabaseMissing('group_channel_alert_cards', ['id' => $card->id]);
    }

    private function alertBot(): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS]['enabled'] = true;

        return GroupChannelBot::query()->create([
            'bot_name' => 'Alert Bot',
            'bot_token' => '123456:test-token',
            'alerts_api_token' => 'alerts-test-token',
            'alerts_api_token_fingerprint' => hash('sha256', 'alerts-test-token'),
            'token_fingerprint' => hash('sha256', '123456:test-token'),
            'webhook_secret' => str_repeat('a', 48),
            'admin_id' => '100500',
            'group_name' => 'Test alerts channel',
            'group_link' => 'https://t.me/test_alerts_channel',
            'chat_type' => 'channel',
            'chat_id' => '-1001234567890',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }

    /** @return array<string, mixed> */
    private function alert(string $uid, string $title, string $oblastUid, string $startedAt): array
    {
        return [
            'id' => (int) ('9'.$uid),
            'location_title' => $title,
            'location_type' => 'raion',
            'started_at' => $startedAt,
            'finished_at' => null,
            'updated_at' => $startedAt,
            'alert_type' => 'air_raid',
            'location_uid' => $uid,
            'location_oblast' => GroupChannelBot::ALERT_REGIONS[$oblastUid],
            'location_oblast_uid' => $oblastUid,
            'location_raion' => '',
            'notes' => null,
            'calculated' => null,
        ];
    }
}
