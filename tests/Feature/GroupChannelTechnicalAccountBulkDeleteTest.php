<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelTechnicalDeleteTask;
use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Models\User;
use App\Services\GroupChannelTelethonClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GroupChannelTechnicalAccountBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_uses_existing_account_without_prechecking_channel_rights(): void
    {
        $user = User::factory()->create();
        $bot = $this->bot();
        $account = $this->technicalAccount(status: 'error', active: false);

        $telethon = Mockery::mock(GroupChannelTelethonClient::class);
        $telethon->shouldReceive('call')
            ->once()
            ->withArgs(function (string $action, TechnicalAccount $selected, array $payload, int $timeout) use ($account): bool {
                return $action === 'group_channel_bulk_count'
                    && $selected->is($account)
                    && $payload['peer'] === 'https://t.me/test_channel'
                    && $payload['mode'] === 'period'
                    && $timeout === 180;
            })
            ->andReturn(['ok' => true, 'count' => 27]);
        $this->app->instance(GroupChannelTelethonClient::class, $telethon);

        $this->actingAs($user)
            ->post(route('admin.group-channel.technical-delete.preview', $bot), [
                'technical_account_id' => $account->id,
                'mode' => 'period',
                'date_from' => '2025-01-01T00:00',
                'date_to' => '2025-12-31T23:59',
            ])
            ->assertRedirect()
            ->assertSessionHas('group_channel_technical_delete_preview.count', 27)
            ->assertSessionHas('group_channel_technical_delete_preview.technical_account_name', 'Старый техаккаунт');
    }

    public function test_confirmation_creates_pending_task_for_separate_worker(): void
    {
        $user = User::factory()->create();
        $bot = $this->bot();
        $account = $this->technicalAccount();
        $token = str_repeat('a', 40);

        $this->actingAs($user)
            ->withSession([
                'group_channel_technical_delete' => [
                    'token' => $token,
                    'bot_id' => $bot->id,
                    'technical_account_id' => $account->id,
                    'technical_account_name' => $account->name,
                    'criteria' => [
                        'mode' => 'all',
                        'count' => null,
                        'date_from' => null,
                        'date_to' => null,
                    ],
                    'count' => 150,
                ],
            ])
            ->post(route('admin.group-channel.technical-delete.execute', $bot), [
                'token' => $token,
            ])
            ->assertRedirect()
            ->assertSessionHas('toast.title', 'Удаление запущено');

        $this->assertDatabaseHas('group_channel_technical_delete_tasks', [
            'group_channel_bot_id' => $bot->id,
            'technical_account_id' => $account->id,
            'technical_account_name' => $account->name,
            'status' => GroupChannelTechnicalDeleteTask::STATUS_PENDING,
            'matched_count' => 150,
        ]);
    }

    public function test_separate_worker_marks_task_completed(): void
    {
        $bot = $this->bot();
        $account = $this->technicalAccount();
        $task = GroupChannelTechnicalDeleteTask::query()->create([
            'group_channel_bot_id' => $bot->id,
            'technical_account_id' => $account->id,
            'technical_account_name' => $account->name,
            'mode' => 'last',
            'criteria' => [
                'mode' => 'last',
                'count' => 10,
                'date_from' => null,
                'date_to' => null,
            ],
            'matched_count' => 10,
        ]);

        $telethon = Mockery::mock(GroupChannelTelethonClient::class);
        $telethon->shouldReceive('call')
            ->once()
            ->withArgs(fn (string $action, TechnicalAccount $selected, array $payload, int $timeout): bool => $action === 'group_channel_bulk_delete'
                && $selected->is($account)
                && $payload['mode'] === 'last'
                && $timeout === 3600)
            ->andReturn([
                'ok' => true,
                'matched_count' => 10,
                'deleted_count' => 10,
                'failed_count' => 0,
            ]);
        $this->app->instance(GroupChannelTelethonClient::class, $telethon);

        $this->artisan('skyguardian:group-channel-technical-delete:work --once')
            ->assertSuccessful();

        $task->refresh();
        $this->assertSame(GroupChannelTechnicalDeleteTask::STATUS_COMPLETED, $task->status);
        $this->assertSame(10, $task->deleted_count);
        $this->assertNotNull($task->finished_at);
    }

    public function test_management_panel_lists_all_existing_technical_accounts(): void
    {
        $user = User::factory()->create();
        $this->bot();
        $this->technicalAccount(status: 'error', active: false);

        $this->actingAs($user)
            ->get(route('admin.group-channel'))
            ->assertOk()
            ->assertSee('Удаление через техаккаунт')
            ->assertSee('Старый техаккаунт')
            ->assertSee('Предварительная проверка прав не выполняется');
    }

    private function bot(): GroupChannelBot
    {
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings['technical_account_bulk_delete']['enabled'] = true;

        return GroupChannelBot::query()->create([
            'bot_name' => 'Manager Bot',
            'bot_token' => '123456:abcdefghijklmnopqrstuvwxyz',
            'token_fingerprint' => hash('sha256', '123456:abcdefghijklmnopqrstuvwxyz'),
            'webhook_secret' => str_repeat('b', 48),
            'admin_id' => '100500',
            'group_name' => 'Test channel',
            'group_link' => 'https://t.me/test_channel',
            'chat_type' => 'channel',
            'chat_id' => '-100500',
            'is_active' => true,
            'module_settings' => $settings,
        ]);
    }

    private function technicalAccount(string $status = 'connected', bool $active = true): TechnicalAccount
    {
        $api = TelegramApi::query()->create([
            'name' => 'API',
            'api_id' => random_int(100000, 999999),
            'api_hash' => str_repeat('c', 32),
            'is_active' => true,
        ]);

        return TechnicalAccount::query()->create([
            'telegram_api_id' => $api->id,
            'name' => 'Старый техаккаунт',
            'auth_method' => 'phone',
            'phone' => '+380501234567',
            'session' => 'session-string',
            'status' => $status,
            'is_active' => $active,
        ]);
    }
}
