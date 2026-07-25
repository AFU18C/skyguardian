<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SystemMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_system_metrics(): void
    {
        $this->get(route('admin.system.metrics'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_read_system_metrics(): void
    {
        $this->mock(SystemMetricsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('snapshot')
                ->once()
                ->andReturn([
                    'cpu' => 17,
                    'memory' => 35,
                    'disk' => 19,
                ]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson(route('admin.system.metrics'))
            ->assertOk()
            ->assertExactJson([
                'cpu' => 17,
                'memory' => 35,
                'disk' => 19,
            ]);
    }
}
