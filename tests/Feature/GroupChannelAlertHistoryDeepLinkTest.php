<?php

namespace Tests\Feature;

use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelBot;
use App\Services\GroupChannelWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertHistoryDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_start_deep_link_routes_to_encoded_bot_and_sends_history_only_to_user(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 7001],
        ]));

        $bot = $this->bot('-1001111111111', 'target_alerts');
        $this->bot('-1002222222222', 'other_alerts');
        $cycle = CarbonImmutable::parse('2026-08-07T17:00:00Z');
        $until = CarbonImmutable::parse('2026-08-07T18:00:00Z');

        GroupChannelAlertEvent::query()->create([
            'group_channel_bot_id' => $bot->id,
            'event_key' => 'deep-link-history-1',
            'kind' => GroupChannelAlertEvent::KIND_END,
            'region_uid' => '1403',
            'scope_region_uid' => '14',
            'region_name' => 'Бучанський район',
            'alert_type' => 'air_raid',
            'event_at' => CarbonImmutable::parse('2026-08-07T17:31:00Z'),
            'status' => GroupChannelAlertEvent::STATUS_SENT,
            'sent_at' => CarbonImmutable::parse('2026-08-07T17:31:01Z'),
        ]);

        $payload = implode('_', [
            'ah',
            $bot->id,
            '14',
            'a',
            $cycle->getTimestamp(),
            $until->getTimestamp(),
        ]);
        $response = $this
            ->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 99001,
                'message' => [
                    'message_id' => 41,
                    'date' => $until->getTimestamp(),
                    'from' => [
                        'id' => 777,
                        'is_bot' => false,
                        'first_name' => 'User',
                    ],
                    'chat' => [
                        'id' => 777,
                        'type' => 'private',
                    ],
                    'text' => '/start '.$payload,
                ],
            ]);

        $response->assertOk();
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === '777'
                && str_contains((string) ($request['text'] ?? ''), 'ВІДБІЙ ПІД ЧАС ЦІЄЇ ТРИВОГИ')
                && str_contains((string) ($request['text'] ?? ''), 'Київська область')
                && str_contains((string) ($request['text'] ?? ''), 'Бучанський район');
        });
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
        Http::assertNotSent(function (Request $request) use ($bot): bool {
            return str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === (string) $bot->chat_id;
        });
    }

    public function test_old_callback_button_redirects_user_to_bot_without_editing_channel_post(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => true,
        ]));

        $bot = $this->bot('-1001111111111', 'target_alerts');
        $cycle = CarbonImmutable::parse('2026-08-07T17:00:00Z');
        $messageDate = CarbonImmutable::parse('2026-08-07T18:00:00Z');

        app(GroupChannelWebhookService::class)->handle($bot, [
            'callback_query' => [
                'id' => 'legacy-history-button',
                'from' => ['id' => 777],
                'data' => 'sg_ah:14:air_raid:'.$cycle->getTimestamp().':show',
                'message' => [
                    'message_id' => 501,
                    'date' => $messageDate->getTimestamp(),
                    'chat' => ['id' => $bot->chat_id],
                    'text' => 'old active card',
                ],
            ],
        ]);

        Http::assertSent(function (Request $request) use ($bot): bool {
            return str_ends_with($request->url(), '/answerCallbackQuery')
                && ($request['callback_query_id'] ?? null) === 'legacy-history-button'
                && str_starts_with(
                    (string) ($request['url'] ?? ''),
                    'https://t.me/test_alert_bot?start=ah_'.$bot->id.'_14_a_',
                );
        });
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    private function bot(string $chatId, string $groupName): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS]['enabled'] = true;
        $token = '123456:test-token';
        $secret = str_repeat('a', 48);

        return GroupChannelBot::query()->create([
            'bot_name' => 'Alert Bot',
            'bot_token' => $token,
            'token_fingerprint' => hash('sha256', $token),
            'webhook_secret' => $secret,
            'admin_id' => '100500',
            'group_name' => $groupName,
            'group_link' => 'https://t.me/'.$groupName,
            'chat_type' => 'channel',
            'chat_id' => $chatId,
            'bot_username' => 'test_alert_bot',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }
}
