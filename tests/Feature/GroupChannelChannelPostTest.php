<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelChannelPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_post_is_saved_for_bulk_delete_after_webhook_delivery(): void
    {
        $bot = $this->botWithModules(['bulk_delete']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson(route('group-channel.webhook', [
                'fingerprint' => $bot->token_fingerprint,
            ]), [
                'update_id' => 100,
                'channel_post' => [
                    'message_id' => 501,
                    'date' => now()->timestamp,
                    'chat' => [
                        'id' => -100500,
                        'type' => 'channel',
                        'title' => 'Test channel',
                    ],
                    'sender_chat' => [
                        'id' => -100500,
                        'type' => 'channel',
                        'title' => 'Test channel',
                    ],
                    'text' => 'Новая публикация канала',
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('group_channel_messages', [
            'group_channel_bot_id' => $bot->id,
            'telegram_message_id' => '501',
            'text' => 'Новая публикация канала',
        ]);
        $this->assertNotNull($bot->fresh()->last_update_at);
    }

    public function test_manual_webhook_registration_subscribes_to_channel_posts(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules([]);
        $oldSecret = $bot->webhook_secret;

        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->actingAs($user)
            ->post(route('admin.group-channel.webhook.register', $bot))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $bot->refresh();
        Http::assertSent(function (Request $request) use ($bot, $oldSecret): bool {
            if (! str_ends_with($request->url(), '/setWebhook')) {
                return false;
            }

            $updates = json_decode((string) $request['allowed_updates'], true);

            return in_array('channel_post', $updates, true)
                && in_array('edited_channel_post', $updates, true)
                && $request['url'] === route('group-channel.webhook', ['fingerprint' => $bot->token_fingerprint])
                && ! str_contains((string) $request['url'], $oldSecret)
                && $request['secret_token'] === $bot->webhook_secret;
        });
        $this->assertNotSame($oldSecret, $bot->webhook_secret);
        $this->assertNotNull($bot->fresh()->webhook_registered_at);
    }

    public function test_module_checkbox_is_saved_individually_and_connects_webhook(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules(['publications']);

        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->actingAs($user)
            ->patchJson(route('admin.group-channel.modules.toggle', [$bot, 'bulk_delete']), [
                'enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('toast.type', 'success');

        $bot->refresh();
        $this->assertTrue($bot->moduleEnabled('bulk_delete'));
        $this->assertTrue($bot->moduleEnabled('publications'));
        $this->assertNotNull($bot->webhook_registered_at);

        $this->actingAs($user)
            ->patchJson(route('admin.group-channel.modules.toggle', [$bot, 'bulk_delete']), [
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $bot->refresh();
        $this->assertFalse($bot->moduleEnabled('bulk_delete'));
        $this->assertTrue($bot->moduleEnabled('publications'));
    }

    public function test_management_page_does_not_show_obsolete_save_functions_button(): void
    {
        $user = User::factory()->create();
        $this->botWithModules(['bulk_delete']);

        $this->actingAs($user)
            ->get(route('admin.group-channel'))
            ->assertOk()
            ->assertDontSee('Сохранить функции');
    }

    private function botWithModules(array $enabled): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        foreach ($enabled as $module) {
            $settings[$module]['enabled'] = true;
        }

        return GroupChannelBot::query()->create([
            'bot_name' => 'Manager Bot',
            'bot_token' => '123456:abcdefghijklmnopqrstuvwxyz',
            'token_fingerprint' => hash('sha256', '123456:abcdefghijklmnopqrstuvwxyz'),
            'webhook_secret' => str_repeat('a', 48),
            'admin_id' => '100500',
            'group_name' => 'Test channel',
            'group_link' => 'https://t.me/test_channel',
            'chat_type' => 'channel',
            'chat_id' => '-100500',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }
}
