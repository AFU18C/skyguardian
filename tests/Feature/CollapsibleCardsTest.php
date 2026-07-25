<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollapsibleCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_cards_are_collapsed_by_default(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount();

        Source::query()->create([
            'technical_account_id' => $account->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'OSINT мониторинг',
            'source_peer' => '@osint_source',
            'destination_peer' => '@destination',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.news.index'))
            ->assertOk()
            ->assertSee('data-collapsible-card', false)
            ->assertSee('data-card-toggle', false)
            ->assertSee('data-card-details hidden', false);
    }

    public function test_telegram_cards_are_collapsed_and_redundant_text_is_removed(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount();

        $this->actingAs($user)
            ->get(route('admin.telegram.index'))
            ->assertOk()
            ->assertSee($account->name)
            ->assertSee('data-collapsible-card', false)
            ->assertSee('data-card-toggle', false)
            ->assertSee('data-card-details hidden', false)
            ->assertDontSee('API Hash хранится зашифрованно и отображается только частично')
            ->assertDontSee('Создание, код Telegram, пароль 2FA и QR-вход проходят как единый сценарий');
    }

    private function createAccount(): TechnicalAccount
    {
        $api = TelegramApi::query()->create([
            'name' => 'Основной API',
            'api_id' => 12345678,
            'api_hash' => '1234567890abcdef1234567890abcdef',
            'is_active' => true,
        ]);

        return TechnicalAccount::query()->create([
            'telegram_api_id' => $api->id,
            'name' => 'Новости 0986414076',
            'auth_method' => 'phone',
            'phone' => '+380986414076',
            'username' => 'BigXaos1989',
            'is_active' => true,
            'status' => 'connected',
        ]);
    }
}
