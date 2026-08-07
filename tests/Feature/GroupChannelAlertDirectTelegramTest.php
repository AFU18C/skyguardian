<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Services\DirectGroupChannelTelegramService;
use App\Services\GroupChannelAlertPublicationService;
use App\Services\GroupChannelTelegramService;
use App\Services\GroupedAlertTelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class GroupChannelAlertDirectTelegramTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_publication_bypasses_legacy_grouped_telegram_interceptor(): void
    {
        $this->app->bind(GroupChannelTelegramService::class, GroupedAlertTelegramService::class);

        $service = $this->app->make(GroupChannelAlertPublicationService::class);
        $property = (new ReflectionClass($service))->getProperty('telegram');
        $property->setAccessible(true);

        $this->assertInstanceOf(
            DirectGroupChannelTelegramService::class,
            $property->getValue($service),
        );
    }

    public function test_direct_alert_service_adds_map_button_to_plain_active_card(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 1001],
        ]));

        $bot = $this->bot();

        app(DirectGroupChannelTelegramService::class)->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 Київська область — Броварський район\n🕒 Початок: 20:04",
        ]);

        $request = Http::recorded()[0][0];
        $button = $request['reply_markup']['inline_keyboard'][0][0] ?? null;

        $this->assertSame(GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_TEXT, $button['text'] ?? null);
        $this->assertSame(GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_URL, $button['url'] ?? null);
    }

    public function test_direct_alert_service_uses_custom_map_button_settings(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 1002],
        ]));

        $bot = $this->bot([
            'module_settings' => [
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS => [
                    'enabled' => true,
                    'map_button_enabled' => true,
                    'map_button_text' => '🗺 Переглянути мапу',
                    'map_button_url' => 'https://example.com/map',
                ],
            ],
        ]);

        app(DirectGroupChannelTelegramService::class)->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "✅ ВІДБІЙ ТРИВОГИ\n\n📍 Броварський район\n🕒 Відбій: 20:10",
        ]);

        $request = Http::recorded()[0][0];
        $button = $request['reply_markup']['inline_keyboard'][0][0] ?? null;

        $this->assertSame('🗺 Переглянути мапу', $button['text'] ?? null);
        $this->assertSame('https://example.com/map', $button['url'] ?? null);
    }

    public function test_direct_alert_service_preserves_history_toggle_when_map_button_is_disabled(): void
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
            'text' => "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 Київська область",
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
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 1003],
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
            'text' => "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 Полтавська область — Полтавський район",
        ]);

        $request = Http::recorded()[0][0];

        $this->assertFalse(isset($request['reply_markup']));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function bot(array $overrides = []): GroupChannelBot
    {
        return GroupChannelBot::query()->create(array_merge([
            'bot_name' => 'Alert Bot',
            'bot_token' => '123456:test-token',
            'token_fingerprint' => hash('sha256', '123456:test-token'),
            'webhook_secret' => str_repeat('a', 48),
            'admin_id' => '100500',
            'group_name' => 'Alerts',
            'group_link' => 'https://t.me/alerts',
            'chat_type' => 'channel',
            'chat_id' => '-1001234567890',
            'is_active' => true,
        ], $overrides));
    }
}
