<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChannelAlertsApiCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_check_runs_publication_cycle_and_reports_counts(): void
    {
        Http::fake([
            'https://api.alerts.in.ua/*' => Http::response([
                'alerts' => [],
            ]),
        ]);

        $user = User::factory()->create();
        $settings = GroupChannelBot::defaultModuleSettings();
        $settings[GroupChannelBot::MODULE_ALERT_PUBLICATIONS]['enabled'] = true;

        $bot = GroupChannelBot::query()->create([
            'bot_name' => 'Alert Bot',
            'bot_token' => '123456:test-token',
            'alerts_api_token' => 'alerts-test-token',
            'alerts_api_token_fingerprint' => hash('sha256', 'alerts-test-token'),
            'token_fingerprint' => hash('sha256', '123456:test-token'),
            'webhook_secret' => str_repeat('a', 48),
            'admin_id' => '100500',
            'group_name' => 'Test alerts channel',
            'group_link' => 'https://t.me/test_alerts_channel',
            'chat_type' => 'channel',
            'chat_id' => '-1001234567890',
            'is_active' => true,
            'module_settings' => $settings,
        ]);

        $response = $this->actingAs($user)->post(route('admin.group-channel.alerts-api-check', $bot));

        $response->assertRedirect();
        $response->assertSessionHas('toast.title', 'API и публикация работают');
        $response->assertSessionHas('toast.message', function (string $message): bool {
            return str_contains($message, 'Активных событий: 0.')
                && str_contains($message, 'Добавлено в очередь: 0.')
                && str_contains($message, 'Отправлено: 0.');
        });
    }
}
