<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('Настройки ещё не добавлены')
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
        $account = $this->telegramAccount();

        $this->actingAs($user)
            ->get(route('news.settings.edit', $account))
            ->assertOk()
            ->assertSee('Редактировать Telegram API и технический аккаунт')
            ->assertSee('Сохранить')
            ->assertSee('Удалить');
    }

    public function test_news_settings_supports_legacy_plaintext_api_credentials(): void
    {
        $user = User::factory()->create();
        $account = $this->telegramAccount();

        DB::table('telegram_accounts')
            ->where('id', $account->id)
            ->update([
                'api_id' => '33042494',
                'api_hash' => str_repeat('a', 32),
            ]);

        $this->actingAs($user)
            ->get(route('news.settings'))
            ->assertOk()
            ->assertSee('API ID: 33042494');

        $this->actingAs($user)
            ->get(route('news.settings.edit', $account))
            ->assertOk()
            ->assertSee('value="33042494"', false);
    }

    public function test_news_channels_opens_separate_add_form_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('news.channels'))
            ->assertOk()
            ->assertSee('Каналы данных')
            ->assertSee(route('news.channels.create'))
            ->assertSee('Каналы данных ещё не добавлены')
            ->assertDontSee('Источники сообщений и каналы публикации новостей.')
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
            ->assertSee('Оригинал')
            ->assertSee('Только текст')
            ->assertSee('В обоих форматах ссылки и хештеги удаляются.')
            ->assertSee('Ключевые слова')
            ->assertSee('Стоп-слова')
            ->assertSee('Добавить свой текст в конце сообщения')
            ->assertSee('Свой текст')
            ->assertSee('Частота проверки')
            ->assertSee('Секунды')
            ->assertSee('Минуты')
            ->assertSee('Часы')
            ->assertSee('от 3 секунд до 12 часов');
    }

    public function test_news_channel_edit_form_contains_save_and_delete_buttons(): void
    {
        $user = User::factory()->create();
        $account = $this->telegramAccount();
        $channel = Source::query()->create([
            'telegram_account_id' => $account->id,
            'name' => 'Новости города',
            'type' => 'telegram',
            'identifier' => '@source_channel',
            'publication_identifier' => '@destination_channel',
            'publication_format' => 'original',
            'check_interval_seconds' => 180,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('news.channels.edit', $channel))
            ->assertOk()
            ->assertSee('Редактировать канал данных')
            ->assertSee('Сохранить')
            ->assertSee('Удалить')
            ->assertSee('Ключевые слова')
            ->assertSee('Стоп-слова')
            ->assertSee('Добавить свой текст в конце сообщения');
    }

    public function test_news_lists_use_real_records_statuses_and_toggle_actions(): void
    {
        $user = User::factory()->create();
        $account = $this->telegramAccount(['status' => 'connected', 'connected_at' => now()]);
        $channel = Source::query()->create([
            'telegram_account_id' => $account->id,
            'name' => 'Новости города',
            'type' => 'telegram',
            'identifier' => '@source_channel',
            'publication_identifier' => '@destination_channel',
            'publication_format' => 'text',
            'check_interval_seconds' => 300,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('news.settings'))
            ->assertOk()
            ->assertSee($account->name)
            ->assertSee('Работает')
            ->assertSee(route('news.settings.edit', $account))
            ->assertDontSee('Настройки ещё не добавлены');

        $this->actingAs($user)
            ->get(route('news.channels'))
            ->assertOk()
            ->assertSee($channel->name)
            ->assertSee('Работает')
            ->assertSee('@destination_channel')
            ->assertSee('Только текст')
            ->assertSee(route('news.channels.edit', $channel))
            ->assertDontSee('Каналы данных ещё не добавлены');

        $this->actingAs($user)
            ->patch(route('news.channels.toggle', $channel))
            ->assertRedirect(route('news.channels'));

        $this->assertFalse($channel->fresh()->is_active);

        $this->actingAs($user)
            ->get(route('news.channels'))
            ->assertSee('Выключен');
    }

    private function telegramAccount(array $overrides = []): TelegramAccount
    {
        return TelegramAccount::query()->create(array_merge([
            'name' => 'Telegram 33042494',
            'api_id' => '33042494',
            'api_hash' => str_repeat('a', 32),
            'login_method' => 'phone',
            'phone' => '+380000000000',
            'status' => 'not_connected',
        ], $overrides));
    }
}
