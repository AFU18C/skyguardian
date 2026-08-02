<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelManagementUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_delete_preview_does_not_fail_for_has_many_relation(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules(['bulk_delete']);
        GroupChannelMessage::query()->create([
            'group_channel_bot_id' => $bot->id,
            'telegram_message_id' => '101',
            'telegram_user_id' => '55',
            'text' => 'Сообщение для удаления',
            'telegram_created_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(
            route('admin.group-channel.bulk-delete.preview', $bot),
            ['mode' => 'last', 'count' => '10'],
        );

        $response
            ->assertRedirect()
            ->assertSessionHas('group_channel_bulk_delete_preview.count', 1);
    }

    public function test_saving_one_module_does_not_reset_another_module_settings(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules(['antispam', 'welcome'], [
            'antispam' => ['delete_links' => true],
        ]);

        $response = $this->actingAs($user)->put(
            route('admin.group-channel.module-settings.update', $bot),
            [
                'module' => 'welcome',
                'settings' => [
                    'welcome' => [
                        'text' => 'Добро пожаловать',
                        'rules' => 'Правила группы',
                    ],
                ],
            ],
        );

        $response
            ->assertRedirect()
            ->assertSessionHas('open_group_channel_manage', $bot->id)
            ->assertSessionHas('open_group_channel_module', 'welcome');

        $bot->refresh();
        $this->assertSame('Добро пожаловать', $bot->moduleSetting('welcome', 'text'));
        $this->assertTrue($bot->moduleSetting('antispam', 'delete_links'));
    }

    public function test_system_message_settings_are_saved_without_changing_other_modules(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules(['system_messages', 'antispam'], [
            'antispam' => ['delete_links' => true],
        ]);

        $this->actingAs($user)->put(
            route('admin.group-channel.module-settings.update', $bot),
            [
                'module' => 'system_messages',
                'settings' => [
                    'system_messages' => [
                        'member_events' => '1',
                        'chat_changes' => '1',
                    ],
                ],
            ],
        )->assertRedirect();

        $bot->refresh();
        $this->assertTrue($bot->moduleSetting('system_messages', 'member_events'));
        $this->assertFalse($bot->moduleSetting('system_messages', 'pinned_messages'));
        $this->assertTrue($bot->moduleSetting('system_messages', 'chat_changes'));
        $this->assertFalse($bot->moduleSetting('system_messages', 'other_events'));
        $this->assertTrue($bot->moduleSetting('antispam', 'delete_links'));
    }

    public function test_connection_check_updates_compact_rights_and_returns_to_management_modal(): void
    {
        $user = User::factory()->create();
        $bot = $this->botWithModules([]);

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/getMe')) {
                return Http::response(['ok' => true, 'result' => [
                    'id' => 700,
                    'username' => 'manager_bot',
                ]]);
            }

            if (str_ends_with($request->url(), '/getChat')) {
                return Http::response(['ok' => true, 'result' => [
                    'id' => -100500,
                    'type' => 'supergroup',
                    'title' => 'Test group',
                ]]);
            }

            return Http::response(['ok' => true, 'result' => [
                'status' => 'administrator',
                'can_delete_messages' => true,
                'can_pin_messages' => true,
                'can_restrict_members' => true,
                'can_invite_users' => true,
                'can_manage_chat' => true,
                'can_manage_topics' => false,
            ]]);
        });

        $response = $this->actingAs($user)->post(route('admin.group-channel.check', $bot));

        $response
            ->assertRedirect()
            ->assertSessionHas('open_group_channel_manage', $bot->id)
            ->assertSessionHas('toast.type', 'success');

        $bot->refresh();
        $this->assertSame('connected', $bot->status);
        $this->assertTrue((bool) data_get($bot->permissions, 'delete_messages'));
    }

    private function botWithModules(array $enabled, array $overrides = []): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        foreach ($enabled as $module) {
            $settings[$module]['enabled'] = true;
        }
        $settings = array_replace_recursive($settings, $overrides);

        return GroupChannelBot::query()->create([
            'bot_name' => 'Manager Bot',
            'bot_token' => '123456:abcdefghijklmnopqrstuvwxyz',
            'token_fingerprint' => hash('sha256', '123456:abcdefghijklmnopqrstuvwxyz'),
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
