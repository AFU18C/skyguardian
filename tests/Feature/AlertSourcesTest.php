<?php

namespace Tests\Feature;

use App\Models\AlertSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_source_can_be_created_with_telegram_https_links(): void
    {
        $response = $this->post(route('alerts.sources.store'), [
            'name' => 'Тестовый источник',
            'type' => 'telegram',
            'address' => 'https://t.me/test_source',
            'publication_chat' => 'https://t.me/test_publication',
            'check_interval' => 60,
        ]);

        $response->assertRedirect(route('alerts.sources'));
        $this->assertDatabaseHas('alert_sources', ['name' => 'Тестовый источник']);
    }

    public function test_telegram_source_rejects_non_telegram_link(): void
    {
        $response = $this->from(route('alerts.sources.create'))->post(route('alerts.sources.store'), [
            'name' => 'Неверный источник',
            'type' => 'telegram',
            'address' => 'https://example.com/channel',
            'publication_chat' => 'https://t.me/test_publication',
            'check_interval' => 60,
        ]);

        $response->assertRedirect(route('alerts.sources.create'));
        $response->assertSessionHasErrors('address');
        $this->assertSame(0, AlertSource::query()->count());
    }
}
