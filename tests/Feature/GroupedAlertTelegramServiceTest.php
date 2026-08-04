<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Services\GroupedAlertTelegramService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupedAlertTelegramServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_oblast_has_its_own_post_and_new_locations_edit_that_post(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();
        $nextMessageId = 700;
        Http::fake(function (Request $request) use (&$nextMessageId) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $nextMessageId++;
            }

            return Http::response([
                'ok' => true,
                'result' => ['message_id' => $nextMessageId],
            ]);
        });

        $bot = $this->bot();
        $service = app(GroupedAlertTelegramService::class);

        $service->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 Харківська область — Харківський район\n📍 Полтавська область — Кременчуцький район\n⚠️ Повітряна тривога\n🕒 Початок: 22:14",
        ]);
        $service->request($bot, 'sendMessage', [
            'chat_id' => $bot->chat_id,
            'text' => "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 Харківська область — Харківський район — Харківська територіальна громада\n📍 Харківська область — м. Харків\n⚠️ Повітряна тривога\n🎯 КАБи у напрямку півночі Харківщини\n🕒 Початок: 22:19",
        ]);

        Http::assertSentCount(3);
        $requests = Http::recorded();
        $this->assertStringEndsWith('/sendMessage', $requests[0][0]->url());
        $this->assertStringEndsWith('/sendMessage', $requests[1][0]->url());
        $this->assertStringEndsWith('/editMessageText', $requests[2][0]->url());

        $kharkivInitial = (string) $requests[0][0]['text'];
        $poltavaInitial = (string) $requests[1][0]['text'];
        $kharkivUpdated = (string) $requests[2][0]['text'];

        $this->assertStringContainsString('📍 <b>Харківська область</b>', $kharkivInitial);
        $this->assertStringNotContainsString('Полтавська область', $kharkivInitial);
        $this->assertStringContainsString('📍 <b>Полтавська область</b>', $poltavaInitial);
        $this->assertStringNotContainsString('Харківська область', $poltavaInitial);

        $this->assertSame('HTML', $requests[2][0]['parse_mode']);
        $this->assertSame(701, $requests[2][0]['message_id']);
        $this->assertStringContainsString('<b>🚨 ПОВІТРЯНА ТРИВОГА</b>', $kharkivUpdated);
        $this->assertStringContainsString('━━━━━━━━━━━━━━', $kharkivUpdated);
        $this->assertStringContainsString('📍 <b>Харківська область</b>', $kharkivUpdated);
        $this->assertStringContainsString('🔴 <b>СТАТУС: АКТИВНА</b>', $kharkivUpdated);
        $this->assertStringContainsString('<b>Активні території:</b>', $kharkivUpdated);
        $this->assertStringContainsString('› Харківський район', $kharkivUpdated);
        $this->assertStringContainsString('› м. Харків', $kharkivUpdated);
        $this->assertStringContainsString('› Харківська територіальна громада', $kharkivUpdated);
        $this->assertStringNotContainsString('⚠️ Повітряна тривога', $kharkivUpdated);
        $this->assertStringNotContainsString('Полтавська область', $kharkivUpdated);
        $this->assertStringContainsString('🎯 КАБи у напрямку півночі Харківщини', $kharkivUpdated);
        $this->assertStringContainsString('🕒 Початок: <b>22:14</b>', $kharkivUpdated);
        $this->assertStringContainsString('🔄 Оновлено: <b>', $kharkivUpdated);
    }

    public function test_partial_and_full_all_clear_edit_the_original_post_and_show_duration(): void
    {
        CarbonImmutable::setTestNow('2026-08-05T00:47:00+03:00');
        config(['cache.default' => 'array']);
        Cache::flush();
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 777],
        ]));

        try {
            $bot = $this->bot();
            $service = app(GroupedAlertTelegramService::class);

            $service->request($bot, 'sendMessage', [
                'chat_id' => $bot->chat_id,
                'text' => "🚨 ПОВІТРЯНА ТРИВОГА\n\n📍 Харківська область — Харківський район\n📍 Харківська область — м. Харків\n⚠️ Повітряна тривога\n🕒 Початок: 00:14",
            ]);
            $service->request($bot, 'sendMessage', [
                'chat_id' => $bot->chat_id,
                'text' => "✅ ВІДБІЙ ТРИВОГИ\n\n📍 Харківська область — Харківський район\n🕒 Відбій: 00:30",
            ]);
            $service->request($bot, 'sendMessage', [
                'chat_id' => $bot->chat_id,
                'text' => "✅ ВІДБІЙ ТРИВОГИ\n\n📍 Харківська область — м. Харків\n🕒 Відбій: 00:47",
            ]);

            Http::assertSentCount(3);
            $requests = Http::recorded();
            $this->assertStringEndsWith('/sendMessage', $requests[0][0]->url());
            $this->assertStringEndsWith('/editMessageText', $requests[1][0]->url());
            $this->assertStringEndsWith('/editMessageText', $requests[2][0]->url());
            $this->assertSame(777, $requests[1][0]['message_id']);
            $this->assertSame(777, $requests[2][0]['message_id']);

            $partial = (string) $requests[1][0]['text'];
            $this->assertStringContainsString('<b>🚨 ПОВІТРЯНА ТРИВОГА</b>', $partial);
            $this->assertStringContainsString('🔴 <b>СТАТУС: АКТИВНА</b>', $partial);
            $this->assertStringContainsString('› м. Харків', $partial);
            $this->assertStringNotContainsString('› Харківський район', $partial);
            $this->assertStringContainsString('🕒 Початок: <b>00:14</b>', $partial);
            $this->assertStringContainsString('🔄 Оновлено: <b>00:47</b>', $partial);

            $allClear = (string) $requests[2][0]['text'];
            $this->assertStringContainsString('<b>🟢 ТРИВОГУ ЗАВЕРШЕНО</b>', $allClear);
            $this->assertStringContainsString('✅ <b>СТАТУС: БЕЗПЕЧНО</b>', $allClear);
            $this->assertStringContainsString('<b>Тривога діяла:</b>', $allClear);
            $this->assertStringContainsString('› Харківський район', $allClear);
            $this->assertStringContainsString('› м. Харків', $allClear);
            $this->assertStringContainsString('🕒 <b>00:14 → 00:47</b>', $allClear);
            $this->assertStringContainsString('⏱ Тривалість: <b>33 хв</b>', $allClear);
        } finally {
            CarbonImmutable::setTestNow();
        }
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
