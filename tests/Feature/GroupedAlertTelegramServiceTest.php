<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Services\GroupedAlertTelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupedAlertTelegramServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_with_same_type_and_time_are_combined_by_editing_one_post(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();
        Http::fake(fn (Request $request) => Http::response([
            'ok' => true,
            'result' => ['message_id' => 777],
        ]));

        $bot = GroupChannelBot::query()->create([
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
        $service = app(GroupedAlertTelegramService::class);

        $service->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 Харківська область — Харківський район\n⚠️ Повітряна тривога\n🕒 Початок: 22:14",
        ]);
        $service->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 Харківська область — Харківський район — Харківська територіальна громада\n📍 Харківська область — м. Харків\n⚠️ Повітряна тривога\n🎯 КАБи у напрямку півночі Харківщини\n🕒 Початок: 22:14",
        ]);

        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertStringEndsWith('/sendMessage', $requests[0][0]->url());
        $this->assertStringEndsWith('/editMessageText', $requests[1][0]->url());

        $payload = $requests[1][0]->data();
        $text = (string) $payload['text'];

        $this->assertSame('HTML', $payload['parse_mode']);
        $this->assertSame(777, $payload['message_id']);
        $this->assertStringContainsString('<b>🚨 ПОВІТРЯНА ТРИВОГА</b>', $text);
        $this->assertStringContainsString('📍 <b>Харківська область</b>', $text);
        $this->assertStringContainsString('• Харківський район', $text);
        $this->assertStringContainsString('• м. Харків', $text);
        $this->assertStringContainsString('• Харківська територіальна громада', $text);
        $this->assertSame(1, substr_count($text, 'Харківська область'));
        $this->assertStringContainsString('🎯 КАБи у напрямку півночі Харківщини', $text);
        $this->assertStringContainsString('🕒 Початок: <b>22:14</b>', $text);
    }
}
