<?php

namespace Tests\Feature;

use App\Contracts\TelegramGateway;
use App\Models\NewsPublication;
use App\Models\Source;
use App\Models\TelegramAccount;
use App\Models\TelegramApp;
use App\Models\User;
use App\Services\NewsPollingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fakes\FakeTelegramGateway;
use Tests\TestCase;

class TemplatePagesTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegramGateway $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram = new FakeTelegramGateway();
        $this->app->instance(TelegramGateway::class, $this->telegram);
    }

    public function test_public_and_admin_template_routes_are_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Важная информация');

        $this->get('/dashboard')->assertRedirect('/login');

        $user = User::factory()->create();

        foreach ([
            'dashboard' => 'Главная',
            'news.channels' => 'Каналы данных',
            'news.settings' => 'Настройка',
            'alerts.channels' => 'Каналы данных',
            'alerts.settings' => 'Настройка',
        ] as $route => $text) {
            $this->actingAs($user)
                ->get(route($route))
                ->assertOk()
                ->assertSee($text);
        }
    }

    public function test_legacy_prototype_records_stay_hidden_from_news(): void
    {
        $user = User::factory()->create();
        $legacyAccount = TelegramAccount::query()->create([
            'name' => 'Старый прототип API',
            'purpose' => 'news',
            'api_id' => '123456',
            'api_hash' => str_repeat('a', 32),
            'login_method' => 'phone',
            'status' => 'not_connected',
        ]);
        $legacySource = Source::query()->create([
            'telegram_account_id' => $legacyAccount->id,
            'name' => 'Старый прототип источника',
            'type' => 'telegram',
            'identifier' => '@legacy_source',
            'publication_identifier' => '@legacy_destination',
            'is_active' => true,
        ]);

        $this->assertNull($legacySource->purpose);

        $this->actingAs($user)
            ->get(route('news.settings'))
            ->assertOk()
            ->assertSee('Данные ещё не добавлены')
            ->assertDontSee($legacyAccount->name);

        $this->actingAs($user)
            ->get(route('news.channels'))
            ->assertOk()
            ->assertSee('Каналы данных ещё не добавлены')
            ->assertDontSee($legacySource->name);
    }

    public function test_telegram_app_credentials_are_encrypted_and_real_records_are_listed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('news.settings.store'), [
                'name' => 'Новости — основной API',
                'api_id' => '12345678',
                'api_hash' => str_repeat('b', 32),
            ])
            ->assertRedirect();

        $telegramApp = TelegramApp::query()->sole();

        $this->assertSame('12345678', $telegramApp->api_id);
        $this->assertSame(str_repeat('b', 32), $telegramApp->api_hash);
        $this->assertNotSame('12345678', DB::table('telegram_apps')->value('api_id'));
        $this->assertNotSame(str_repeat('b', 32), DB::table('telegram_apps')->value('api_hash'));

        $this->actingAs($user)
            ->get(route('news.settings'))
            ->assertOk()
            ->assertSee($telegramApp->name)
            ->assertSee('Добавить техаккаунт');
    }

    public function test_channel_form_requires_a_connected_technical_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('news.channels.create'))
            ->assertRedirect(route('news.settings'));

        [$telegramApp, $account] = $this->connectedAccount();

        $this->actingAs($user)
            ->get(route('news.channels.create'))
            ->assertOk()
            ->assertSee('Канал или группа — источник сообщений')
            ->assertSee($account->name)
            ->assertSee('от 3 секунд до 12 часов');

        $this->actingAs($user)
            ->get(route('news.accounts.edit', [$telegramApp, $account]))
            ->assertOk()
            ->assertSee('Код и пароль 2FA не сохраняются');
    }

    public function test_creating_a_channel_checks_access_and_starts_after_latest_message(): void
    {
        $user = User::factory()->create();
        [, $account] = $this->connectedAccount();
        $this->telegram->inspection = [
            'source_peer_id' => '-100100',
            'destination_peer_id' => '-100200',
            'latest_message_id' => 77,
        ];

        $this->actingAs($user)
            ->post(route('news.channels.store'), [
                'name' => 'Новости города',
                'identifier' => '@source_channel',
                'telegram_account_id' => $account->id,
                'publication_identifier' => '@destination_channel',
                'publication_format' => 'original',
                'keywords' => 'Запорожье, новости',
                'stop_words' => 'реклама',
                'append_custom_text' => '1',
                'custom_text' => 'Подписывайтесь',
                'frequency_value' => 3,
                'frequency_unit' => 'seconds',
            ])
            ->assertRedirect(route('news.channels'));

        $source = Source::query()->sole();

        $this->assertSame('news', $source->purpose);
        $this->assertSame('-100100', $source->peer_id);
        $this->assertSame('-100200', $source->publication_peer_id);
        $this->assertSame(77, $source->last_message_id);
        $this->assertSame(3, $source->check_interval_seconds);
        $this->assertTrue($source->is_active);
        $this->assertFalse($source->resume_from_latest);
    }

    public function test_polling_creates_durable_deduplicated_jobs_before_advancing_cursor(): void
    {
        Queue::fake();
        [, $account] = $this->connectedAccount();
        $source = $this->newsSource($account, [
            'last_message_id' => 10,
            'keywords' => 'важно',
        ]);
        $this->telegram->messages = [
            ['id' => 11, 'grouped_id' => 'album-1', 'message' => 'Важно: фото 1', 'media' => ['_' => 'messageMediaPhoto']],
            ['id' => 12, 'grouped_id' => 'album-1', 'message' => '', 'media' => ['_' => 'messageMediaPhoto']],
            ['id' => 13, 'message' => 'Не подходит под фильтр'],
        ];

        $result = $this->app->make(NewsPollingService::class)->poll($source);

        $this->assertSame(3, $result['received']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(13, $source->fresh()->last_message_id);
        $this->assertDatabaseHas('news_publications', [
            'source_id' => $source->id,
            'telegram_message_id' => 12,
            'grouped_id' => 'album-1',
            'status' => NewsPublication::STATUS_PENDING,
        ]);
        $this->assertDatabaseCount('news_publications', 2);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('news_publications', 'message_text'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('news_publications', 'media'));

        $this->app->make(NewsPollingService::class)->poll($source->fresh());
        $this->assertDatabaseCount('news_publications', 2);
    }

    public function test_first_poll_sets_baseline_without_publishing_old_messages(): void
    {
        Queue::fake();
        [, $account] = $this->connectedAccount();
        $source = $this->newsSource($account, [
            'last_message_id' => null,
            'resume_from_latest' => true,
        ]);
        $this->telegram->latestMessageId = 500;

        $result = $this->app->make(NewsPollingService::class)->poll($source);

        $this->assertTrue($result['baseline']);
        $this->assertSame(500, $source->fresh()->last_message_id);
        $this->assertDatabaseCount('news_publications', 0);
    }

    public function test_flood_wait_takes_priority_without_advancing_message_cursor(): void
    {
        [, $account] = $this->connectedAccount();
        $source = $this->newsSource($account, ['last_message_id' => 41]);
        $this->telegram->messagesException = new RuntimeException('FLOOD_WAIT_60');

        $result = $this->app->make(NewsPollingService::class)->poll($source);

        $this->assertSame(60, $result['flood_wait']);
        $this->assertSame(41, $source->fresh()->last_message_id);
        $this->assertTrue($source->fresh()->flood_wait_until->isFuture());
        $this->assertSame('rate_limited', $account->fresh()->status);
    }

    public function test_deleting_technical_account_keeps_linked_channel_visible_and_disabled(): void
    {
        $user = User::factory()->create();
        [$telegramApp, $account] = $this->connectedAccount();
        $source = $this->newsSource($account);
        NewsPublication::query()->create([
            'source_id' => $source->id,
            'telegram_account_id' => $account->id,
            'telegram_message_id' => 11,
            'message_ids' => [11],
            'source_peer_id' => '-100100',
            'destination_peer_id' => '-100200',
            'dedupe_key' => hash('sha256', 'delete-account-test'),
            'status' => NewsPublication::STATUS_SENT,
        ]);

        $this->actingAs($user)
            ->delete(route('news.accounts.destroy', [$telegramApp, $account]))
            ->assertRedirect(route('news.settings.edit', $telegramApp));

        $source->refresh();
        $this->assertNull($source->telegram_account_id);
        $this->assertFalse($source->is_active);
        $this->assertSame('off', $source->statusState());
        $this->assertNull(NewsPublication::query()->sole()->telegram_account_id);

        $this->actingAs($user)
            ->get(route('news.channels'))
            ->assertOk()
            ->assertSee($source->name)
            ->assertSee('Отключён')
            ->assertSee('Техаккаунт удалён');
    }

    /**
     * @return array{TelegramApp, TelegramAccount}
     */
    private function connectedAccount(): array
    {
        $telegramApp = TelegramApp::query()->create([
            'purpose' => 'news',
            'name' => 'Новости — API',
            'api_id' => '12345678',
            'api_hash' => str_repeat('c', 32),
            'is_active' => true,
        ]);
        $account = TelegramAccount::query()->create([
            'telegram_app_id' => $telegramApp->id,
            'purpose' => 'news',
            'name' => 'Новости — техаккаунт',
            'login_method' => 'phone',
            'phone' => '+380000000000',
            'status' => 'connected',
            'is_active' => true,
            'connected_at' => now(),
        ]);

        return [$telegramApp, $account];
    }

    private function newsSource(TelegramAccount $account, array $overrides = []): Source
    {
        return Source::query()->create(array_merge([
            'telegram_account_id' => $account->id,
            'purpose' => 'news',
            'name' => 'Новости города',
            'type' => 'telegram',
            'identifier' => '@source_channel',
            'peer_id' => '-100100',
            'publication_identifier' => '@destination_channel',
            'publication_peer_id' => '-100200',
            'publication_format' => 'original',
            'check_interval_seconds' => 3,
            'last_message_id' => 10,
            'next_check_at' => now(),
            'is_active' => true,
            'is_available' => true,
            'resume_from_latest' => false,
        ], $overrides));
    }
}
