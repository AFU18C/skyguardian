<?php

namespace App\Services;

use App\Models\SiteMenuItem;
use App\Models\SiteSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SiteContentService
{
    public const CACHE_KEY = 'site-content:settings';

    public const MENU_CACHE_KEY = 'site-content:menu';

    public function settings(): array
    {
        if (! $this->tablesAvailable()) {
            return $this->defaults();
        }

        try {
            return Cache::rememberForever(self::CACHE_KEY, function (): array {
                return array_replace(
                    $this->defaults(),
                    SiteSetting::query()->pluck('value', 'key')->all(),
                );
            });
        } catch (Throwable) {
            return $this->defaults();
        }
    }

    public function menu(): Collection
    {
        if (! $this->tablesAvailable()) {
            return collect();
        }

        try {
            return Cache::rememberForever(self::MENU_CACHE_KEY, function (): Collection {
                return SiteMenuItem::query()
                    ->visible()
                    ->whereNull('parent_id')
                    ->with([
                        'page',
                        'children' => fn ($query) => $query->visible()->with('page'),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();
            });
        } catch (Throwable) {
            return collect();
        }
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::MENU_CACHE_KEY);
    }

    public function defaults(): array
    {
        return [
            'site_name' => 'SkyGuardian',
            'site_tagline' => 'Система мониторинга информации',
            'language' => 'ru',
            'timezone' => 'Europe/Kyiv',
            'theme' => 'classic',
            'logo_path' => null,
            'favicon_path' => null,
            'login_visual_eyebrow' => 'Защищённый доступ',
            'login_visual_title' => 'SkyGuardian',
            'login_visual_description' => 'Панель управления источниками, техническими аккаунтами и Telegram-интеграцией.',
            'login_form_eyebrow' => 'Административная панель',
            'login_form_title' => 'Вход в систему',
            'login_form_description' => 'Введите Email и пароль администратора.',
            'login_email_label' => 'Email',
            'login_password_label' => 'Пароль',
            'login_remember_label' => 'Запомнить меня',
            'login_button_label' => 'Войти',
            'login_back_label' => 'Вернуться на сайт',
            'login_accent_color' => '#687052',
            'login_background_color' => '#20231d',
            'login_panel_color' => '#f8f5e9',
            'login_logo_path' => null,
            'login_background_path' => null,
        ];
    }

    private function tablesAvailable(): bool
    {
        try {
            return Schema::hasTable('site_settings') && Schema::hasTable('site_menu_items');
        } catch (Throwable) {
            return false;
        }
    }
}
