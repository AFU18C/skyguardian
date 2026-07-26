<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupChannelBulkDeleteNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_delete_panel_explains_that_old_history_is_unavailable(): void
    {
        $user = User::factory()->create();
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings['bulk_delete']['enabled'] = true;

        GroupChannelBot::query()->create([
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

        $this->actingAs($user)
            ->get(route('admin.group-channel'))
            ->assertOk()
            ->assertSee('Доступно для удаления: 0')
            ->assertSee('Telegram Bot API не передаёт старую историю');
    }
}
