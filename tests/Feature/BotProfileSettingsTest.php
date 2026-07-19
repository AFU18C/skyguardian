<?php

namespace Tests\Feature;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:abcdefghijklmnopqrstuvwxyz_ABCD';

    public function test_alert_bot_profile_can_be_saved_replaced_and_disabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('alerts.settings.update'), [
                'bot_name' => 'Бот тревог',
                'bot_token' => self::TOKEN,
                'administrator_telegram_id' => '5012107873',
            ])
            ->assertRedirect();

        $settings = AlertBotSetting::query()->firstOrFail();
        $this->assertSame('Бот тревог', $settings->bot_name);
        $this->assertSame(self::TOKEN, $settings->bot_token);
        $this->assertSame('5012107873', $settings->administrator_telegram_id);

        $this->actingAs($user)
            ->put(route('alerts.settings.update'), [
                'bot_name' => 'Бот тревог',
                'bot_token' => '',
                'administrator_telegram_id' => '5012107873',
            ])
            ->assertRedirect();

        $this->assertSame(self::TOKEN, $settings->fresh()->bot_token);

        $this->actingAs($user)
            ->put(route('alerts.settings.update'), [
                'bot_name' => 'Бот тревог',
                'administrator_telegram_id' => '5012107873',
                'remove_bot_token' => '1',
            ])
            ->assertRedirect();

        $settings->refresh();
        $this->assertNull($settings->bot_token);
        $this->assertSame('not_configured', $settings->bot_status);
    }

    public function test_news_bot_profile_can_be_saved_and_disabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('news.settings.update'), [
                'bot_name' => 'Новостной бот',
                'bot_token' => self::TOKEN,
                'administrator_telegram_id' => '-1001234567890',
            ])
            ->assertRedirect();

        $settings = NewsBotSetting::query()->firstOrFail();
        $this->assertSame('Новостной бот', $settings->bot_name);
        $this->assertSame(self::TOKEN, $settings->bot_token);
        $this->assertSame('-1001234567890', $settings->administrator_telegram_id);

        $this->actingAs($user)
            ->put(route('news.settings.update'), [
                'bot_name' => 'Новостной бот',
                'administrator_telegram_id' => '-1001234567890',
                'remove_bot_token' => '1',
            ])
            ->assertRedirect();

        $settings->refresh();
        $this->assertNull($settings->bot_token);
        $this->assertSame('stopped', $settings->service_status);
    }

    public function test_invalid_bot_credentials_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('alerts.settings'))
            ->put(route('alerts.settings.update'), [
                'bot_token' => 'invalid-token',
                'administrator_telegram_id' => 'not-a-number',
            ])
            ->assertRedirect(route('alerts.settings'))
            ->assertSessionHasErrors(['bot_token', 'administrator_telegram_id']);

        $this->assertDatabaseCount('alert_bot_settings', 0);
    }
}
