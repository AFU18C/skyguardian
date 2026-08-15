<?php

namespace Tests\Feature;

use App\Services\HealthCheckService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_readiness_endpoint_reports_all_required_checks_without_internal_details(): void
    {
        $health = Mockery::mock(HealthCheckService::class);
        $health->shouldReceive('snapshot')->once()->andReturn([
            'healthy' => true,
            'checked_at' => now()->toAtomString(),
            'checks' => collect([
                'database', 'cache', 'scheduler', 'telethon', 'group_channel_telethon', 'disk', 'backup',
            ])->mapWithKeys(fn (string $name): array => [$name => ['status' => 'ok', 'detail' => 'private']])->all(),
        ]);
        $this->app->instance(HealthCheckService::class, $health);

        $this->getJson('/up')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.backup', 'ok')
            ->assertJsonMissing(['detail' => 'private'])
            ->assertHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function test_readiness_endpoint_returns_service_unavailable_when_a_dependency_fails(): void
    {
        $health = Mockery::mock(HealthCheckService::class);
        $health->shouldReceive('snapshot')->once()->andReturn([
            'healthy' => false,
            'checked_at' => now()->toAtomString(),
            'checks' => ['database' => ['status' => 'failed', 'detail' => 'credentials']],
        ]);
        $this->app->instance(HealthCheckService::class, $health);

        $this->getJson('/up')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database', 'failed')
            ->assertDontSee('credentials');
    }

    public function test_scheduler_heartbeat_command_updates_cache_and_is_scheduled_every_minute(): void
    {
        Cache::forget(HealthCheckService::SCHEDULER_HEARTBEAT_KEY);

        $this->artisan('skyguardian:health:heartbeat')->assertSuccessful();

        $this->assertIsInt(Cache::get(HealthCheckService::SCHEDULER_HEARTBEAT_KEY));
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'skyguardian:health:heartbeat'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
