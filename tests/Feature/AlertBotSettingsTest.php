<?php

namespace Tests\Feature;

use App\Models\AlertBotSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertBotSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_bot_settings_can_be_saved(): void
    {
        $this->post('/alerts/settings', [
            'telegram_bot_token' => '123456:test-token',
            'is_enabled' => '1',
        ])->assertRedirect('/alerts/settings');

        $settings = AlertBotSetting::query()->firstOrFail();

        $this->assertSame('123456:test-token', $settings->telegram_bot_token);
        $this->assertTrue($settings->is_enabled);
    }

    public function test_saved_token_is_displayed_only_as_a_mask(): void
    {
        AlertBotSetting::query()->create([
            'telegram_bot_token' => '123456:very-secret-token',
            'is_enabled' => true,
        ]);

        $this->get('/alerts/settings')
            ->assertOk()
            ->assertSee('Токен сохранён')
            ->assertDontSee('123456:very-secret-token');
    }

    public function test_token_can_be_deleted_and_bot_is_disabled(): void
    {
        AlertBotSetting::query()->create([
            'telegram_bot_token' => '123456:test-token',
            'is_enabled' => true,
        ]);

        $this->delete('/alerts/settings/token')
            ->assertRedirect('/alerts/settings')
            ->assertSessionHas('success');

        $settings = AlertBotSetting::query()->firstOrFail();

        $this->assertNull($settings->telegram_bot_token);
        $this->assertFalse($settings->is_enabled);
    }

    public function test_telegram_connection_can_be_checked(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['username' => 'SkyGuardianAlertBot'],
            ]),
        ]);

        $this->post('/alerts/settings/test', [
            'telegram_bot_token' => '123456:test-token',
        ])->assertRedirect('/alerts/settings')
            ->assertSessionHas('success');
    }
}
