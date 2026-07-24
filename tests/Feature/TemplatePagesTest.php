<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplatePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_placeholder_and_login_are_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Важная информация')
            ->assertSee('skyguardian.pp.ua');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Добро пожаловать');
    }

    public function test_guest_is_redirected_from_admin_panel(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_template_routes_are_available_to_authorized_user(): void
    {
        $user = User::factory()->create();

        $routes = [
            'dashboard' => 'Главная',
            'news.channels' => 'Каналы данных',
            'news.settings' => 'Настройка',
            'alerts.channels' => 'Каналы данных',
            'alerts.settings' => 'Настройка',
            'settings.groups' => 'Управление группой',
            'settings.site' => 'Управление сайтом',
        ];

        foreach ($routes as $route => $text) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertSee($text);
        }
    }

    public function test_custom_not_found_page_is_used(): void
    {
        $this->get('/missing-page')
            ->assertNotFound()
            ->assertSee('Страница не найдена');
    }
}
