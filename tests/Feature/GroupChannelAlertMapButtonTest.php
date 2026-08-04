<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Services\GroupChannelTelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertMapButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_button_is_added_below_active_and_completed_alert_cards(): void
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

            $this->assertSame('🗺 Мапа тривог України', $button['text'] ?? null);
            $this->assertSame('https://skyguardian.pp.ua/', $button['url'] ?? null);
        }
    }

    public function test_map_button_is_not_added_to_regular_posts(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 901],
        ]));

        $bot = $this->bot();
        $service = app(GroupChannelTelegramService::class);

        $service->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => 'Звичайне повідомлення каналу',
        ]);

        $request = Http::recorded()[0][0];

        $this->assertNull($request['reply_markup']);
    }

    private function bot(): GroupChannelBot
    {
        return GroupChannelBot::query()->create([
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
        ]);
    }
}
