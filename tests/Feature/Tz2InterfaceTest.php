<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Tz2InterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_and_login_page_are_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('SkyGuardian')
            ->assertSee('Сайт находится в разработке');

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Вход в систему');
    }

    public function test_admin_authentication_is_working(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('StrongPassword123'),
        ]);

        $this->get('/admin')->assertRedirect('/admin/login');

        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'StrongPassword123',
            'remember' => '1',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk()->assertSee('Раздел находится в разработке');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_news_and_air_alert_sources_are_separated_and_crud_works(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount();

        $this->actingAs($user)->post(route('admin.news.store'), [
            'form_context' => 'source-create',
            'name' => 'Новости региона',
            'technical_account_id' => $account->id,
            'source_peer' => '@news_source',
            'destination_peer' => '@news_destination',
            'check_interval' => 5,
            'check_interval_unit' => 'minutes',
            'is_active' => '1',
            'copy_mode' => 'original',
            'strip_links' => '1',
            'strip_hashtags' => '1',
            'strip_mentions' => '1',
            'remove_phrases' => "реклама\nподписывайтесь на источник",
            'footer_html' => '<b>SkyGuardian</b><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(2)">bad</a>',
            'rules' => [
                ['key' => 'include_keywords', 'value' => 'важно', 'is_active' => '1', 'priority' => 100],
            ],
        ])->assertSessionHasNoErrors();

        $source = Source::query()->firstOrFail();
        $this->assertSame(Source::TYPE_NEWS, $source->type);
        $this->assertTrue($source->is_active);
        $this->assertNull($source->last_message_id);
        $this->assertDatabaseHas('source_rules', ['source_id' => $source->id, 'key' => 'include_keywords']);
        $this->assertDatabaseHas('source_rules', ['source_id' => $source->id, 'key' => 'copy_mode']);
        $this->assertDatabaseHas('source_rules', ['source_id' => $source->id, 'key' => 'strip_links']);

        $footer = (string) data_get($source->rules()->where('key', 'footer_html')->firstOrFail()->value, 'value');
        $this->assertStringContainsString('<b>SkyGuardian</b>', $footer);
        $this->assertStringNotContainsString('<script', $footer);
        $this->assertStringNotContainsString('javascript:', $footer);
        $this->assertStringNotContainsString('onclick', $footer);

        $this->actingAs($user)->get(route('admin.news.index'))
            ->assertOk()
            ->assertSee('Новости региона')
            ->assertSee('Копирование сообщений')
            ->assertSee('Свой текст внизу публикации');

        $airAlertResponse = $this->actingAs($user)->get(route('admin.air-alert.index'));
        $airAlertResponse->assertOk();
        $content = $airAlertResponse->getContent();
        $position = mb_strpos($content, 'Новости региона');
        $context = $position === false ? '' : mb_substr($content, max(0, $position - 220), 500);
        $this->assertFalse($position !== false, "Новостной источник попал в раздел тревог. Контекст: {$context}");

        $source->forceFill(['last_message_id' => 99])->save();

        $this->actingAs($user)->put(route('admin.news.update', $source), [
            'form_context' => 'source-'.$source->id,
            'name' => 'Обновлённые новости',
            'technical_account_id' => $account->id,
            'source_peer' => '@news_source',
            'destination_peer' => '@news_destination',
            'check_interval' => 10,
            'check_interval_unit' => 'minutes',
            'is_active' => '0',
            'copy_mode' => 'text_only',
            'strip_links' => '0',
            'strip_hashtags' => '0',
            'strip_mentions' => '0',
            'remove_phrases' => '',
            'footer_html' => '<i>Обновлено</i>',
            'reset_cursor' => '1',
            'rules' => [],
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertSame('Обновлённые новости', $source->name);
        $this->assertFalse($source->is_active);
        $this->assertNull($source->last_message_id);
        $this->assertSame('text_only', data_get($source->rules()->where('key', 'copy_mode')->firstOrFail()->value, 'value'));

        $this->actingAs($user)->delete(route('admin.news.destroy', $source));
        $this->assertDatabaseMissing('sources', ['id' => $source->id]);
    }

    public function test_telegram_configuration_and_account_crud_are_connected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('admin.telegram.apis.store'), [
            'form_context' => 'api-create',
            'name' => 'Основной API',
            'api_id' => 12345678,
            'api_hash' => '1234567890abcdef1234567890abcdef',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $api = TelegramApi::query()->firstOrFail();

        $this->actingAs($user)->post(route('admin.telegram.accounts.store'), [
            'form_context' => 'account-create',
            'name' => 'Основной аккаунт',
            'auth_method' => 'phone',
            'phone' => '+380671234567',
            'telegram_api_id' => $api->id,
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $account = TechnicalAccount::query()->firstOrFail();
        $source = Source::query()->create([
            'technical_account_id' => $account->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'Связанный источник',
            'source_peer' => '@source',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.telegram.index'));
        $response->assertOk()
            ->assertSee('Основной аккаунт')
            ->assertDontSee('1234567890abcdef1234567890abcdef')
            ->assertDontSee('+380671234567');

        $this->actingAs($user)->put(route('admin.telegram.accounts.update', $account), [
            'form_context' => 'account-'.$account->id,
            'name' => 'Обновлённый аккаунт',
            'auth_method' => 'phone',
            'phone' => '',
            'telegram_api_id' => $api->id,
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+380671234567', $account->refresh()->phone);

        $this->actingAs($user)->delete(route('admin.telegram.accounts.destroy', $account));
        $source->refresh();
        $this->assertNull($source->technical_account_id);
        $this->assertFalse($source->is_active);
        $this->assertSame('account_missing', $source->status);
    }

    private function createAccount(): TechnicalAccount
    {
        $api = TelegramApi::query()->create([
            'name' => 'Test API',
            'api_id' => random_int(100000, 999999),
            'api_hash' => '1234567890abcdef1234567890abcdef',
            'is_active' => true,
        ]);

        return TechnicalAccount::query()->create([
            'telegram_api_id' => $api->id,
            'name' => 'Test Account',
            'auth_method' => 'phone',
            'phone' => '+380671234567',
            'is_active' => true,
        ]);
    }
}
