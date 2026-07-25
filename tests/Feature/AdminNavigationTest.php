<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_admin_menu_items_are_displayed_in_the_required_order(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSeeInOrder([
                'Настройки Telegram',
                'Настройки сайта',
                'Группа-Канал',
            ]);
    }

    public function test_new_admin_pages_are_available_only_after_authentication(): void
    {
        $this->get(route('admin.site-settings'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.group-channel'))->assertRedirect(route('admin.login'));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.site-settings'))
            ->assertOk()
            ->assertSee('Настройки сайта');

        $this->actingAs($user)
            ->get(route('admin.group-channel'))
            ->assertOk()
            ->assertSee('Группа-Канал');
    }
}
