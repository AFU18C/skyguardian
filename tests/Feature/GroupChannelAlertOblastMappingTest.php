<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Services\AlertsInUaClient;
use App\Services\GroupChannelAlertPublicationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertOblastMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_child_locations_are_mapped_by_oblast_name_and_published(): void
    {
        CarbonImmutable::setTestNow('2026-08-04T10:00:00Z');

        try {
            $baselineAlert = $this->alert(
                id: 8757,
                uid: '16',
                title: 'Луганська область',
                type: 'oblast',
                oblast: 'Луганська область',
                startedAt: '2022-04-04T16:45:39.000Z',
            );
            $cityAlert = $this->alert(
                id: 241597,
                uid: '5349',
                title: 'м. Марганець',
                type: 'city',
                oblast: 'Дніпропетровська область',
                startedAt: '2026-08-04T09:00:00.000Z',
            );
            $raionAlert = $this->alert(
                id: 241705,
                uid: '125',
                title: 'Ізюмський район',
                type: 'raion',
                oblast: 'Харківська область',
                startedAt: '2026-08-04T10:01:00.000Z',
            );

            Http::fake([
                'https://api.alerts.in.ua/*' => Http::response([
                    'alerts' => [$baselineAlert, $cityAlert, $raionAlert],
                ]),
                'https://api.telegram.org/*' => Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 301],
                ]),
            ]);

            $settings = GroupChannelBot::defaultModuleSettings();
            $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS]['enabled'] = true;

            $bot = GroupChannelBot::query()->create([
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

            $service = app(GroupChannelAlertPublicationService::class);
            $baseline = $service->processSnapshot($bot, [$baselineAlert]);

            $this->assertTrue($baseline['baseline']);
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));

            CarbonImmutable::setTestNow('2026-08-04T10:02:00Z');

            $alerts = app(AlertsInUaClient::class)->activeAlerts('alerts-test-token');
            $this->assertSame('9', $alerts[1]['location_oblast_uid']);
            $this->assertSame('22', $alerts[2]['location_oblast_uid']);

            $result = $service->processSnapshot($bot->fresh(), $alerts);

            $this->assertFalse($result['baseline']);
            $this->assertSame(3, $result['active']);
            $this->assertSame(0, $result['queued']);
            $this->assertSame(2, $result['sent']);

            $this->assertDatabaseHas('group_channel_alert_states', [
                'group_channel_bot_id' => $bot->id,
                'region_uid' => '5349',
                'scope_region_uid' => '9',
                'region_name' => 'Дніпропетровська область — м. Марганець',
            ]);
            $this->assertDatabaseHas('group_channel_alert_states', [
                'group_channel_bot_id' => $bot->id,
                'region_uid' => '125',
                'scope_region_uid' => '22',
                'region_name' => 'Харківська область — Ізюмський район',
            ]);
            $this->assertDatabaseHas('group_channel_alert_cards', [
                'group_channel_bot_id' => $bot->id,
                'scope_region_uid' => '9',
                'alert_type' => 'air_raid',
                'telegram_message_id' => 301,
            ]);
            $this->assertDatabaseHas('group_channel_alert_cards', [
                'group_channel_bot_id' => $bot->id,
                'scope_region_uid' => '22',
                'alert_type' => 'air_raid',
                'telegram_message_id' => 301,
            ]);
            $this->assertDatabaseMissing('group_channel_alert_events', [
                'group_channel_bot_id' => $bot->id,
                'kind' => 'start',
            ]);

            Http::assertSent(function (Request $request): bool {
                $text = (string) ($request['text'] ?? '');

                return str_contains($request->url(), 'api.telegram.org')
                    && str_contains($text, '📍 Дніпропетровська область')
                    && str_contains($text, '› м. Марганець — 12:00');
            });
            Http::assertSent(function (Request $request): bool {
                $text = (string) ($request['text'] ?? '');

                return str_contains($request->url(), 'api.telegram.org')
                    && str_contains($text, '📍 Харківська область')
                    && str_contains($text, '› Ізюмський район — 13:01');
            });
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function alert(
        int $id,
        string $uid,
        string $title,
        string $type,
        string $oblast,
        string $startedAt,
    ): array {
        return [
            'id' => $id,
            'location_uid' => $uid,
            'location_title' => $title,
            'location_type' => $type,
            'location_oblast' => $oblast,
            'location_oblast_uid' => $uid,
            'location_raion' => null,
            'alert_type' => 'air_raid',
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
            'finished_at' => null,
            'calculated' => null,
            'notes' => null,
        ];
    }
}
