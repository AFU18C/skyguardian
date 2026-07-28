<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_vps_metrics_are_displayed_on_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Нагрузка VPS')
            ->assertSee('data-vps-metrics', false)
            ->assertSee('Процессор')
            ->assertSee('Оперативная память')
            ->assertSee('Диск');
    }

    public function test_vps_metrics_are_not_displayed_in_topbar_on_other_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.site-settings'))
            ->assertOk()
            ->assertDontSee('data-vps-metrics', false)
            ->assertDontSee('Нагрузка VPS');
    }
}
