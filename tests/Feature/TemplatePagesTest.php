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

    public function test_news_settings_opens_separate_add_form_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('news.settings'))
            ->assertOk()
            ->assertSee('Telegram API и технические аккаунты новостей.')
            ->assertSee(route('news.settings.create'))
            ->assertDontSee('Название API');

        $this->actingAs($user)
            ->get(route('news.settings.create'))
            ->assertOk()
            ->assertSee('Добавить Telegram API и технический аккаунт')
            ->assertSee('Название API')
            ->assertSee('API ID')
            ->assertSee('API Hash')
            ->assertSee('Номер телефона')
            ->assertSee('QR-код');
    }

    public function test_news_settings_edit_form_contains_save_and_delete_buttons(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('news.settings.edit', ['account' => 1]))
            ->assertOk()
            ->assertSee('Редактировать Telegram API и технический аккаунт')
            ->assertSee('Сохранить')
            ->assertSee('Удалить');
    }

    public function test_news_channels_opens_separate_add_form_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('news.channels'))
            ->assertOk()
            ->assertSee('Каналы данных')
            ->assertSee(route('news.channels.create'))
            ->assertDontSee('Канал или группа — источник сообщений');

        $this->actingAs($user)
            ->get(route('news.channels.create'))
            ->assertOk()
            ->assertSee('Добавить канал данных')
            ->assertSee('Название')
            ->assertSee('Канал или группа — источник сообщений')
            ->assertSee('Технический аккаунт')
            ->assertSee('Канал или группа для публикации')
            ->assertSee('Формат публикации')
            ->assertSee('Частота проверки');
    }

    public function test_news_channel_edit_form_contains_save_and_delete_buttons(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('news.channels.edit', ['channel' => 1]))
            ->assertOk()
            ->assertSee('Редактировать канал данных')
            ->assertSee('Сохранить')
            ->assertSee('Удалить');
    }
}
