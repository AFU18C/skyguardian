<?php

namespace Tests\Feature;

use App\Models\AlertBotSetting;
use App\Models\NewsBotSetting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BotProfileSettingsTest extends TestCase
{
    private const TOKEN = '123456789:abcdefghijklmnopqrstuvwxyz_ABCD';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('alert_bot_settings');
        Schema::dropIfExists('news_bot_settings');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('alert_bot_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('bot_name', 100)->nullable();
            $table->text('bot_token')->nullable();
            $table->string('administrator_telegram_id', 32)->nullable();
            $table->string('bot_status', 32)->default('not_configured');
            $table->timestamps();
        });

        Schema::create('news_bot_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('bot_name', 100)->nullable();
            $table->text('bot_token')->nullable();
            $table->string('administrator_telegram_id', 32)->nullable();
            $table->string('service_status', 32)->default('stopped');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('alert_bot_settings');
        Schema::dropIfExists('news_bot_settings');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

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
