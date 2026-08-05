<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\User;
use App\Services\GroupChannelTelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertMapButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_map_button_is_added_below_active_and_completed_alert_cards(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 900],
        ]));

        $bot = $this->bot();
        $service = app(GroupChannelTelegramService::class);

        $service->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "<b>🚨 ПОВІТРЯНА ТРИВОГА</b>\n\n🔴 <b>СТАТУС: АКТИВНА</b>",
            'parse_mode' => 'HTML',
        ]);

        $service->request($bot, 'editMessageText', [
            'chat_id' => $bot->chat_id,
            'message_id' => 900,
            'text' => "<b>🟢 ТРИВОГУ ЗАВЕРШЕНО</b>\n\n✅ <b>СТАТУС: БЕЗПЕЧНО</b>",
            'parse_mode' => 'HTML',
        ]);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        foreach ($requests as [$request]) {
            $button = $request['reply_markup']['inline_keyboard'][0][0] ?? null;

            $this->assertSame(GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_TEXT, $button['text'] ?? null);
            $this->assertSame(GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_URL, $button['url'] ?? null);
        }
    }

    public function test_custom_map_button_text_and_url_are_used(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 901],
        ]));

        $bot = $this->bot([
            'module_settings' => [
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS => [
                    'enabled' => true,
                    'map_button_enabled' => true,
                    'map_button_text' => '🛰 Відкрити SkyGuardian',
                    'map_button_url' => 'https://example.com/alerts',
                ],
            ],
        ]);

        app(GroupChannelTelegramService::class)->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => '<b>🚨 ПОВІТРЯНА ТРИВОГА</b>',
            'parse_mode' => 'HTML',
        ]);

        $request = Http::recorded()[0][0];
        $button = $request['reply_markup']['inline_keyboard'][0][0] ?? null;

        $this->assertSame('🛰 Відкрити SkyGuardian', $button['text'] ?? null);
        $this->assertSame('https://example.com/alerts', $button['url'] ?? null);
    }

    public function test_disabled_map_button_is_not_sent_and_is_removed_during_edit(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 902],
        ]));

        $bot = $this->bot([
            'module_settings' => [
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS => [
                    'enabled' => true,
                    'map_button_enabled' => false,
                ],
            ],
        ]);
        $service = app(GroupChannelTelegramService::class);

        $service->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => '<b>🚨 ПОВІТРЯНА ТРИВОГА</b>',
        ]);
        $service->request($bot, 'editMessageText', [
            'chat_id' => $bot->chat_id,
            'message_id' => 902,
            'text' => '<b>🟢 ТРИВОГУ ЗАВЕРШЕНО</b>',
        ]);

        $requests = Http::recorded();

        $this->assertFalse(isset($requests[0][0]['reply_markup']));
        $this->assertSame([], $requests[1][0]['reply_markup']['inline_keyboard'] ?? null);
    }

    public function test_map_button_settings_are_available_and_saved_for_the_channel(): void
    {
        $user = User::factory()->create();
        $bot = $this->bot([
            'module_settings' => [
                GroupChannelBot::MODULE_ALERT_PUBLICATIONS => [
                    'enabled' => true,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('admin.group-channel'))
            ->assertOk()
            ->assertSee('Кнопка карты тревог')
            ->assertSee('Надпись на кнопке')
            ->assertSee('Ссылка кнопки');

        $this->actingAs($user)->put(route('admin.group-channel.alert-settings.update', $bot), [
            'all_ukraine' => '1',
            'alert_types' => array_keys(GroupChannelBot::ALERT_TYPES),
            'publish_start' => '1',
            'publish_end' => '1',
            'disable_notification' => '0',
            'map_button_enabled' => '1',
            'map_button_text' => '🗺 Переглянути мапу',
            'map_button_url' => 'https://example.com/map',
            'start_template' => GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE,
            'end_template' => GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE,
        ])->assertSessionHasNoErrors();

        $bot->refresh();

        $this->assertTrue($bot->moduleSetting(GroupChannelBot::MODULE_ALERT_PUBLICATIONS, 'map_button_enabled'));
        $this->assertSame(
            '🗺 Переглянути мапу',
            $bot->moduleSetting(GroupChannelBot::MODULE_ALERT_PUBLICATIONS, 'map_button_text'),
        );
        $this->assertSame(
            'https://example.com/map',
            $bot->moduleSetting(GroupChannelBot::MODULE_ALERT_PUBLICATIONS, 'map_button_url'),
        );
    }

    public function test_map_button_is_not_added_to_regular_posts(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 903],
        ]));

        $bot = $this->bot();
        $service = app(GroupChannelTelegramService::class);

        $service->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => 'Звичайне повідомлення каналу',
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
