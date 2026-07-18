<?php

namespace Tests\Feature;

use App\Models\AlertSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertSourceCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_manual_telegram_check_updates_only_cached_manual_status(): void
    {
        Http::fake([
            'https://t.me/s/skyguardian_test' => Http::response(
                '<div class="tgme_widget_message">Test publication</div>',
                200,
            ),
        ]);

        $source = AlertSource::query()->create([
            'name' => 'Telegram test',
            'type' => 'telegram',
            'address' => 'https://t.me/skyguardian_test',
            'publication_chat' => 'https://t.me/skyguardian_publication',
            'check_interval' => 60,
        ]);

        $originalUpdatedAt = $source->updated_at;

        $response = $this->postJson(route('alerts.sources.test', $source));

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'manual_status' => 'available',
            ]);

        $this->assertSame(
            'available',
            Cache::get("alert-source:{$source->id}:manual-status"),
        );
        $this->assertNotNull(Cache::get("alert-source:{$source->id}:manual-checked-at"));
        $this->assertTrue($source->fresh()->updated_at->equalTo($originalUpdatedAt));
    }

    public function test_failed_manual_check_is_saved_as_unavailable_in_cache(): void
    {
        Http::fake([
            'https://t.me/s/skyguardian_test' => Http::response('Not found', 404),
        ]);

        $source = AlertSource::query()->create([
            'name' => 'Telegram test',
            'type' => 'telegram',
            'address' => 'https://t.me/skyguardian_test',
            'publication_chat' => 'https://t.me/skyguardian_publication',
            'check_interval' => 60,
        ]);

        $response = $this->postJson(route('alerts.sources.test', $source));

        $response
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'manual_status' => 'unavailable',
            ]);

        $this->assertSame(
            'unavailable',
            Cache::get("alert-source:{$source->id}:manual-status"),
        );
    }

    public function test_non_telegram_source_does_not_offer_a_manual_check_result(): void
    {
        $source = AlertSource::query()->create([
            'name' => 'Website test',
            'type' => 'website',
            'address' => 'https://example.com',
            'publication_chat' => 'https://t.me/skyguardian_publication',
            'check_interval' => 60,
        ]);

        $this->postJson(route('alerts.sources.test', $source))
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ]);

        $this->assertNull(Cache::get("alert-source:{$source->id}:manual-status"));
    }
}
