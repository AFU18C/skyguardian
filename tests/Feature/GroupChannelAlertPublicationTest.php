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
        $this->assertSame(0, $started['queued']);
        $this->assertSame(1, $started['sent']);
        $this->assertDatabaseMissing('group_channel_alert_events', [
            'group_channel_bot_id' => $bot->id,
            'kind' => GroupChannelAlertEvent::KIND_START,
        ]);
        $this->assertDatabaseHas('group_channel_alert_cards', [
            'group_channel_bot_id' => $bot->id,
            'scope_region_uid' => '14',
            'telegram_message_id' => null,
        ]);
        $this->assertDatabaseHas('group_channel_alert_cards', [
            'group_channel_bot_id' => $bot->id,
            'scope_region_uid' => '24',
            'telegram_message_id' => 101,
        ]);
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'Черкаська область')
                && str_contains((string) $request['text'], 'ПОВІТРЯНА ТРИВОГА');
        });

        $ended = $service->processSnapshot($bot->fresh(), []);

        $this->assertSame(2, $ended['queued']);
        $this->assertSame(2, $ended['sent']);
        $this->assertDatabaseCount('group_channel_alert_states', 0);
        $this->assertDatabaseCount('group_channel_alert_cards', 0);
        $this->assertDatabaseCount('group_channel_alert_events', 2);
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'ВІДБІЙ ТРИВОГИ')
                && str_contains((string) $request['text'], 'Київська область');
        });
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'ВІДБІЙ ТРИВОГИ')
                && str_contains((string) $request['text'], 'Черкаська область');
        });
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/deleteMessage')
                && (int) $request['message_id'] === 101;
        });
    }

    public function test_partial_all_clear_publishes_green_post_and_replaces_active_card(): void
    {
        $nextMessageId = 500;
        Http::fake(function (Request $request) use (&$nextMessageId) {
            if (str_ends_with($request->url(), '/deleteMessage')) {
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

        $initial = $service->processSnapshot($bot->fresh(), [
            $this->alert(124, 'м. Харків та тергромада', 'city', 4101, 'air_raid', 22),
            $this->alert(2201, 'Купʼянський район', 'raion', 4102, 'air_raid', 22),
            $this->alert(2202, 'Чугуївський район', 'raion', 4103, 'air_raid', 22),
        ]);

        $this->assertSame(0, $initial['queued']);
        $this->assertSame(1, $initial['sent']);
        $this->assertDatabaseHas('group_channel_alert_cards', [
            'group_channel_bot_id' => $bot->id,
            'scope_region_uid' => '22',
            'alert_type' => 'air_raid',
            'telegram_message_id' => 501,
        ]);

        $partial = $service->processSnapshot($bot->fresh(), [
            $this->alert(2201, 'Купʼянський район', 'raion', 4102, 'air_raid', 22),
            $this->alert(2202, 'Чугуївський район', 'raion', 4103, 'air_raid', 22),
        ]);

        $this->assertSame(1, $partial['queued']);
        $this->assertSame(2, $partial['sent']);
        $this->assertDatabaseHas('group_channel_alert_events', [
            'group_channel_bot_id' => $bot->id,
            'kind' => GroupChannelAlertEvent::KIND_END,
            'region_uid' => '124',
            'scope_region_uid' => '22',
            'status' => GroupChannelAlertEvent::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('group_channel_alert_cards', [
            'group_channel_bot_id' => $bot->id,
            'scope_region_uid' => '22',
            'telegram_message_id' => 503,
        ]);

        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'ВІДБІЙ ТРИВОГИ')
                && str_contains($text, 'Харківська область')
                && str_contains($text, 'СТАТУС: БЕЗПЕЧНО')
                && str_contains($text, 'м. Харків та тергромада')
                && str_contains($text, 'Тривога тривала:');
        });
        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'Купʼянський район')
                && str_contains($text, 'Чугуївський район')
                && ! str_contains($text, 'м. Харків та тергромада')
                && str_contains($text, '🔄 Оновлено:');
        });
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/deleteMessage')
                && (int) $request['message_id'] === 501;
        });

        $fullClear = $service->processSnapshot($bot->fresh(), []);

        $this->assertSame(2, $fullClear['queued']);
        $this->assertSame(2, $fullClear['sent']);
        $this->assertDatabaseCount('group_channel_alert_states', 0);
        $this->assertDatabaseCount('group_channel_alert_cards', 0);
        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'ВІДБІЙ ТРИВОГИ')
                && str_contains($text, 'Харківська область')
                && str_contains($text, 'СТАТУС: БЕЗПЕЧНО')
                && str_contains($text, 'Купʼянський район')
                && str_contains($text, 'Чугуївський район')
                && str_contains($text, 'Тривога тривала:');
        });
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/deleteMessage')
                && (int) $request['message_id'] === 503;
        });
    }

    public function test_unchanged_snapshot_does_not_republish_active_card(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 601],
            ]),
        ]);

        $bot = $this->alertBot();
        $service = app(GroupChannelAlertPublicationService::class);
        $service->processSnapshot($bot, []);

        $alerts = [
            $this->alert(124, 'м. Харків та тергромада', 'city', 5101, 'air_raid', 22),
            $this->alert(2201, 'Купʼянський район', 'raion', 5102, 'air_raid', 22),
        ];
        $service->processSnapshot($bot->fresh(), $alerts);
        Http::assertSentCount(1);

        $unchanged = $service->processSnapshot($bot->fresh(), $alerts);

        $this->assertSame(0, $unchanged['queued']);
        $this->assertSame(0, $unchanged['sent']);
        Http::assertSentCount(1);
    }

    public function test_all_supported_location_levels_and_notes_are_published(): void
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
            $this->alert(22, 'Харківська область', 'oblast', 2001),
            $this->alert(
                2201,
                'Берестинський район',
                'raion',
                2002,
                'air_raid',
                22,
                'Берестинський район',
                'КАБИ → на північ Харківщини!',
            ),
            $this->alert(124, 'м. Харків та тергромада', 'city', 2003, 'air_raid', 22),
            $this->alert(
                2202,
                'Лозівська міська громада',
                'hromada',
                2004,
                'air_raid',
                22,
                'Лозівський район',
            ),
            $this->alert(9999, 'Невідома локація', 'unknown', 2005, 'air_raid', 22),
        ]);

        $this->assertFalse($result['baseline']);
        $this->assertSame(4, $result['active']);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '2201',
            'scope_region_uid' => '22',
            'region_name' => 'Харківська область — Берестинський район',
            'details' => 'КАБИ → на північ Харківщини!',
        ]);
        $this->assertDatabaseHas('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '2202',
            'scope_region_uid' => '22',
            'region_name' => 'Харківська область — Лозівський район — Лозівська міська громада',
        ]);
        $this->assertDatabaseMissing('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '9999',
        ]);
        $this->assertDatabaseCount('group_channel_alert_states', 4);
        Http::assertSent(function (Request $request): bool {
            $text = (string) $request['text'];

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, '📍 Харківська область')
                && str_contains($text, '› Берестинський район — 10:00')
                && str_contains($text, '🎯 КАБИ → на північ Харківщини!')
                && ! str_contains($text, 'Активні території')
                && ! str_contains($text, '🕒 Початок:');
        });
    }

    public function test_active_card_shows_scope_and_individual_start_time_for_each_territory(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 777],
            ]),
        ]);

        $bot = $this->alertBot();
        $service = app(GroupChannelAlertPublicationService::class);
        $service->processSnapshot($bot, []);

        $service->processSnapshot($bot->fresh(), [
            $this->alert(1201, 'Бердянський район', 'raion', 7001, 'air_raid', 12, null, null, '2026-08-07T19:52:00+03:00'),
            $this->alert(1202, 'м. Запоріжжя', 'city', 7002, 'air_raid', 12, null, null, '2026-08-07T19:57:00+03:00'),
        ]);

        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, '🚨 ПОВІТРЯНА ТРИВОГА')
                && str_contains($text, '📍 Запорізька область')
                && str_contains($text, '🔴 СТАТУС: АКТИВНА')
                && str_contains($text, '› Бердянський район — 19:52')
                && str_contains($text, '› м. Запоріжжя — 19:57')
                && str_contains($text, '🔄 Оновлено:')
                && ! str_contains($text, 'Активні території')
                && ! str_contains($text, '🕒 Початок:');
        });
    }

    public function test_selected_oblasts_include_all_their_locations_and_respect_threat_types(): void
    {
        Http::fake();

        $settings = GroupChannelBot::defaultModuleSettings();
        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS] = array_replace(
            $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS],
            [
                'enabled' => true,
                'all_ukraine' => false,
                'region_uids' => ['22'],
                'alert_types' => ['air_raid'],
            ],
        );
        $bot = $this->alertBot($settings);

        $result = app(GroupChannelAlertPublicationService::class)->processSnapshot($bot, [
            $this->alert(2201, 'Берестинський район', 'raion', 3001, 'air_raid', 22),
            $this->alert(124, 'м. Харків та тергромада', 'city', 3002, 'air_raid', 22),
            $this->alert(2202, 'Лозівська міська громада', 'hromada', 3003, 'air_raid', 22),
            $this->alert(1401, 'Бровари', 'city', 3004, 'air_raid', 14),
            $this->alert(2203, 'Ізюмський район', 'raion', 3005, 'artillery_shelling', 22),
        ]);

        $this->assertSame(3, $result['active']);
        $this->assertDatabaseHas('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '2201',
            'scope_region_uid' => '22',
            'alert_type' => 'air_raid',
        ]);
        $this->assertDatabaseHas('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '124',
            'scope_region_uid' => '22',
            'alert_type' => 'air_raid',
        ]);
        $this->assertDatabaseHas('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '2202',
            'scope_region_uid' => '22',
            'alert_type' => 'air_raid',
        ]);
        $this->assertDatabaseMissing('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '1401',
        ]);
        $this->assertDatabaseMissing('group_channel_alert_states', [
            'group_channel_bot_id' => $bot->id,
            'region_uid' => '2203',
        ]);
        $this->assertDatabaseCount('group_channel_alert_states', 3);
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
        ?string $raion = null,
        ?string $notes = null,
        ?string $startedAt = null,
    ): array {
        $oblastUid ??= $regionUid;
        $oblastName = GroupChannelBot::ALERT_REGIONS[(string) $oblastUid] ?? $title;

        return [
            'id' => $id,
            'location_title' => $title,
            'location_type' => $locationType,
            'started_at' => $startedAt ?? '2026-08-04T10:00:00+03:00',
            'finished_at' => null,
            'updated_at' => '2026-08-04T10:00:01+03:00',
            'alert_type' => $alertType,
            'location_uid' => (string) $regionUid,
            'location_oblast' => $oblastName,
            'location_oblast_uid' => (string) $oblastUid,
            'location_raion' => $raion ?? '',
            'notes' => $notes,
            'calculated' => null,
        ];
    }
}
