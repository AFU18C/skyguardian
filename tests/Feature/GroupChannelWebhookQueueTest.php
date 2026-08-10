<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelWebhookUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GroupChannelWebhookQueueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function production_style_webhook_intake_acknowledges_before_side_effects(): void
    {
        config(['skyguardian.webhook_sync_in_tests' => false]);
        $bot = $this->bot();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
                'secret' => $bot->webhook_secret,
            ]), [
                'update_id' => 700,
                'message' => [
                    'message_id' => 701,
                    'date' => now()->timestamp,
                    'chat' => ['id' => -100500, 'type' => 'supergroup'],
                    'from' => ['id' => 99, 'is_bot' => false],
                    'text' => 'https://example.com',
                    'entities' => [['type' => 'url', 'offset' => 0, 'length' => 19]],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('group_channel_webhook_updates', [
            'telegram_update_id' => 700,
            'status' => GroupChannelWebhookUpdate::STATUS_PENDING,
            'attempts' => 0,
        ]);
        $this->assertDatabaseMissing('group_channel_messages', [
            'group_channel_bot_id' => $bot->id,
            'telegram_message_id' => '701',
        ]);
        Http::assertNothingSent();

        $this->artisan('skyguardian:group-channel-webhook-updates:process --limit=50')
            ->assertSuccessful();

        $this->assertDatabaseHas('group_channel_webhook_updates', [
            'telegram_update_id' => 700,
            'status' => GroupChannelWebhookUpdate::STATUS_PROCESSED,
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('group_channel_messages', [
            'group_channel_bot_id' => $bot->id,
            'telegram_message_id' => '701',
            'matched_rule' => 'links',
        ]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessage'));
    }

    private function bot(): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings['antispam']['enabled'] = true;
        $settings['antispam']['delete_links'] = true;
        $token = '123456:abcdefghijklmnopqrstuvwxyz';

        return GroupChannelBot::query()->create([
            'bot_name' => 'Manager Bot',
            'bot_token' => $token,
            'token_fingerprint' => hash('sha256', $token),
            'webhook_secret' => str_repeat('a', 48),
            'admin_id' => '100500',
            'group_name' => 'Test group',
            'group_link' => 'https://t.me/test_group',
            'chat_type' => 'supergroup',
            'chat_id' => '-100500',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }
}
