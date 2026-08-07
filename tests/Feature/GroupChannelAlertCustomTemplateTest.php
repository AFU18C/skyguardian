<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Services\GroupChannelAlertPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertCustomTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_start_template_keeps_working_with_existing_placeholders(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 901],
            ]),
        ]);

        $settings = GroupChannelBot::defaultModuleSettings();
        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS] = array_replace(
            $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS],
            [
                'enabled' => true,
                'start_template' => "ALERT {region}\nTYPE {threat_type}\nTIME {time}",
            ],
        );

        $bot = GroupChannelBot::query()->create([
            'bot_name' => 'Custom Alert Bot',
            'bot_token' => '123456:test-token',
            'alerts_api_token' => 'alerts-test-token',
            'alerts_api_token_fingerprint' => hash('sha256', 'alerts-test-token'),
            'token_fingerprint' => hash('sha256', '123456:test-token'),
            'webhook_secret' => str_repeat('a', 48),
            'admin_id' => '100500',
            'group_name' => 'Custom alerts channel',
            'group_link' => 'https://t.me/custom_alerts_channel',
            'chat_type' => 'channel',
            'chat_id' => '-1001234567890',
            'is_active' => true,
            'module_settings' => $settings,
        ]);

        $service = app(GroupChannelAlertPublicationService::class);
        $service->processSnapshot($bot, []);
        $service->processSnapshot($bot->fresh(), [[
            'id' => 8001,
            'location_title' => 'м. Запоріжжя',
            'location_type' => 'city',
            'started_at' => '2026-08-07T19:52:00+03:00',
            'finished_at' => null,
            'updated_at' => '2026-08-07T19:52:01+03:00',
            'alert_type' => 'air_raid',
            'location_uid' => '1201',
            'location_oblast' => 'Запорізька область',
            'location_oblast_uid' => '12',
            'location_raion' => '',
            'notes' => null,
            'calculated' => null,
        ]]);

        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'ALERT Запорізька область — м. Запоріжжя')
                && str_contains($text, 'TYPE Повітряна тривога')
                && str_contains($text, 'TIME 19:52')
                && ! str_contains($text, 'СТАТУС: АКТИВНА');
        });
    }
}
