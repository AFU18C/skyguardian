<?php

namespace Tests\Feature;

use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelAlertPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_snapshot_is_only_baseline_and_following_changes_are_published(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ]),
        ]);

        $bot = $this->alertBot();
        $service = app(GroupChannelAlertPublicationService::class);

        $baseline = $service->processSnapshot($bot, [
            $this->alert(14, 'Київська область', 'oblast', 1001),
        ]);

        $this->assertTrue($baseline['baseline']);
        $this->assertSame(1, $baseline['active']);
        $this->assertSame(0, $baseline['queued']);
        $this->assertSame(0, $baseline['sent']);
        Http::assertNothingSent();

        $started = $service->processSnapshot($bot->fresh(), [
            $this->alert(14, 'Київська область', 'oblast', 1001),
            $this->alert(24, 'Черкаська область', 'oblast', 1002),
        ]);

        $this->assertFalse($started['baseline']);
        $this->assertSame(1, $started['queued']);
        $this->assertSame(1, $started['sent']);
        $this->assertDatabaseHas('group_channel_alert_events', [
            'group_channel_bot_id' => $bot->id,
            'kind' => GroupChannelAlertEvent::KIND_START,
            'region_uid' => '24',
            'status' => GroupChannelAlertEvent::STATUS_SENT,
        ]);
        Http::assertSent(function (Request $request): bool {
            return str_contains((string) $request['text'], 'Черкаська область')
                && str_contains((string) $request['text'], 'Повітряна тривога');
        });

        $ended = $service->processSnapshot($bot->fresh(), []);

        $this->assertSame(2, $ended['queued']);
        $this->assertSame(2, $ended['sent']);
        $this->assertDatabaseCount('group_channel_alert_states', 0);
        $this->assertDatabaseCount('group_channel_alert_events', 3);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $text = (string) $request['text'];

            return str_contains($text, 'ВІДБІЙ ТРИВОГИ')
                && str_contains($text, 'Київська область')
                && str_contains($text, 'Черкаська область');
        });
    }

    public function test_cities_are_published_while_raions_and_hromadas_are_ignored(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 202],
            ]),
        ]);

        $bot = $this->alertBot();
        $service = app(GroupChannelAlertPublicationService::class);
        $service->processSnapshot($bot, []);

        $result = $service->processSnapshot($bot->fresh(), [
            $this->alert(5001, 'Тестова громада', 'hromada', 2001, 'air_raid', 25),
            $this->alert(5002, 'Тестовий район', 'raion', 2002, 'air_raid', 25),
            $this->alert(5003, 'Чернігів', 'city', 2003, 'air_raid', 25),
        ]);

        $this->assertFalse($result['baseline']);
        $this->assertSame(1, $result['active']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '5003',
            'region_name' => 'Чернігів',
        ]);
        $this->assertDatabaseCount('group_channel_alert_states', 1);
        Http::assertSent(function (Request $request): bool {
            return str_contains((string) $request['text'], 'Чернігів');
        });
    }

    public function test_selected_oblasts_include_their_cities_and_respect_threat_types(): void
    {
        Http::fake();

        $settings = GroupChannelBot::defaultModuleSettings();
        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS] = array_replace(
            $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS],
            [
                'enabled' => true,
                'all_ukraine' => false,
                'region_uids' => ['12'],
                'alert_types' => ['air_raid'],
            ],
        );
        $bot = $this->alertBot($settings);

        $result = app(GroupChannelAlertPublicationService::class)->processSnapshot($bot, [
            $this->alert(12, 'Запорізька область', 'oblast', 3001, 'air_raid'),
            $this->alert(1201, 'Запоріжжя', 'city', 3002, 'air_raid', 12),
            $this->alert(1401, 'Бровари', 'city', 3003, 'air_raid', 14),
            $this->alert(12, 'Запорізька область', 'oblast', 3004, 'artillery_shelling'),
        ]);

        $this->assertSame(2, $result['active']);
        $this->assertDatabaseHas('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '12',
            'alert_type' => 'air_raid',
        ]);
        $this->assertDatabaseHas('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '1201',
            'region_name' => 'Запоріжжя',
            'alert_type' => 'air_raid',
        ]);
        $this->assertDatabaseMissing('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '1401',
        ]);
        $this->assertDatabaseCount('group_channel_alert_states', 2);
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    private function alertBot(?array $settings = null): GroupChannelBot
    {
        $settings ??= GroupChannelBot::defaultModuleSettings();
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

    /**
     * @return array<string, mixed>
     */
    private function alert(
        int $regionUid,
        string $title,
        string $locationType,
        int $id,
        string $alertType = 'air_raid',
        ?int $oblastUid = null,
    ): array {
        $oblastUid ??= $regionUid;
        $oblastName = GroupChannelBot::ALERT_REGIONS[(string) $oblastUid] ?? $title;

        return [
            'id' => $id,
            'location_title' => $title,
            'location_type' => $locationType,
            'started_at' => '2026-08-04T10:00:00+03:00',
            'finished_at' => null,
            'updated_at' => '2026-08-04T10:00:01+03:00',
            'alert_type' => $alertType,
            'location_uid' => (string) $regionUid,
            'location_oblast' => $oblastName,
            'location_oblast_uid' => (string) $oblastUid,
            'location_raion' => '',
            'notes' => null,
            'calculated' => null,
        ];
    }
}
