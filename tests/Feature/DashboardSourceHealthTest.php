<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSourceHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_functional_health_of_news_and_alerts(): void
    {
        $api = TelegramApi::query()->create([
            'name' => 'Test API',
            'api_id' => 123456,
            'api_hash' => 'test-hash',
            'is_active' => true,
        ]);
        $account = TechnicalAccount::query()->create([
            'telegram_api_id' => $api->id,
            'name' => 'Technical account',
            'auth_method' => 'phone',
            'phone' => '+380000000000',
            'session' => 'session',
            'status' => 'connected',
            'is_active' => true,
        ]);

        Source::query()->create([
            'technical_account_id' => $account->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'Новости',
            'source_peer' => '@news',
            'is_active' => true,
            'status' => 'available',
            'check_interval' => 60,
            'check_interval_unit' => 'seconds',
            'last_success_at' => now(),
        ]);
        Source::query()->create([
            'type' => Source::TYPE_AIR_ALERT,
            'name' => 'Тревоги',
            'source_peer' => '@alerts',
            'is_active' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Новости')
            ->assertSee('Работает')
            ->assertSee('Воздушные тревоги')
            ->assertSee('Отключено');
    }
}
