<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_group_channel_bot(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.group-channel.store'), [
            'bot_name' => 'Manager Bot',
            'bot_token' => '123456:abcdefghijklmnopqrstuvwxyz',
            'admin_id' => '100500',
            'group_name' => 'Test group',
            'group_link' => 'https://t.me/test_group',
            'chat_type' => 'group',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('group_channel_bots', [
            'bot_name' => 'Manager Bot',
            'group_link' => 'https://t.me/test_group',
        ]);
    }

    public function test_manual_check_saves_bot_permissions(): void
    {
        $user = User::factory()->create();
        $bot = GroupChannelBot::query()->create([
            'bot_name' => 'Manager Bot',
            'bot_token' => '123456:abcdefghijklmnopqrstuvwxyz',
            'admin_id' => '100500',
            'group_name' => 'Test group',
            'group_link' => 'https://t.me/test_group',
            'chat_type' => 'group',
        ]);

        Http::fake([
            '*getMe' => Http::response(['ok' => true, 'result' => ['id' => 123, 'username' => 'manager_bot']]),
            '*getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001, 'title' => 'Test group', 'type' => 'supergroup']]),
            '*getChatMember' => Http::response(['ok' => true, 'result' => [
                'status' => 'administrator',
                'can_delete_messages' => true,
                'can_pin_messages' => true,
                'can_restrict_members' => true,
                'can_invite_users' => true,
            ]]),
        ]);

        $this->actingAs($user)->post(route('admin.group-channel.check', $bot))->assertRedirect();

        $bot->refresh();
        $this->assertSame('connected', $bot->status);
        $this->assertTrue($bot->permissions['is_administrator']);
    }
}
