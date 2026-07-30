<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\SiteSetting;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteLoginSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_page_is_managed_from_site_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.site-settings'))
            ->assertOk()
            ->assertSee('Авторизация')
            ->assertSee(route('admin.site-settings.login.edit'), false);

        $this->actingAs($user)
            ->get(route('admin.site-settings.login.edit'))
            ->assertOk()
            ->assertSee('Защищённая системная страница')
            ->assertSee('/admin/login');
    }

    public function test_administrator_can_update_login_texts_colors_and_images(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $sourceCount = Source::query()->count();
        $botCount = GroupChannelBot::query()->count();

        $this->actingAs($user)
            ->put(route('admin.site-settings.login.update'), [
                'login_visual_eyebrow' => 'Только для команды',
                'login_visual_title' => 'SkyGuardian Control',
                'login_visual_description' => 'Защищённая панель управления.',
                'login_form_eyebrow' => 'Администратор',
                'login_form_title' => 'Авторизация',
                'login_form_description' => 'Введите данные доступа.',
                'login_email_label' => 'Рабочий Email',
                'login_password_label' => 'Пароль доступа',
                'login_remember_label' => 'Сохранить вход',
                'login_button_label' => 'Продолжить',
                'login_back_label' => 'На главную',
                'login_accent_color' => '#556644',
                'login_background_color' => '#101510',
                'login_panel_color' => '#faf8ef',
                'login_logo' => UploadedFile::fake()->image('login-logo.png', 600, 200),
                'login_background' => UploadedFile::fake()->image('login-background.jpg', 1920, 1080),
            ])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $this->assertSame('Авторизация', SiteSetting::query()->where('key', 'login_form_title')->value('value'));
        $this->assertSame('#556644', SiteSetting::query()->where('key', 'login_accent_color')->value('value'));
        $this->assertNotNull(SiteSetting::query()->where('key', 'login_logo_path')->value('value'));
        $this->assertNotNull(SiteSetting::query()->where('key', 'login_background_path')->value('value'));
        $this->assertSame($sourceCount, Source::query()->count());
        $this->assertSame($botCount, GroupChannelBot::query()->count());

        auth()->logout();

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('SkyGuardian Control')
            ->assertSee('Рабочий Email')
            ->assertSee('Продолжить')
            ->assertSee('#556644', false);
    }

    public function test_preview_is_available_only_to_authenticated_administrator(): void
    {
        $this->get(route('admin.site-settings.login.preview'))
            ->assertRedirect(route('admin.login'));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.site-settings.login.preview'))
            ->assertOk()
            ->assertSee('Предпросмотр авторизации')
            ->assertSee('disabled', false);
    }

    public function test_login_security_flow_is_not_changed_by_visual_settings(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $this->post(route('admin.login.store'), [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
