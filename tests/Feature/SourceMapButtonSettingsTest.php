<?php

namespace Tests\Feature;

use App\Models\GroupChannelBot;
use App\Models\Source;
use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Models\User;
use App\Services\OperationGate;
use App\Services\SourceProcessor;
use App\Services\SourceScheduler;
use App\Services\TelethonClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SourceMapButtonSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_button_setting_is_available_and_saved_for_both_source_editors(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount();
        $cases = [
            [Source::TYPE_NEWS, 'admin.news.index', 'admin.news.store', '@news_source'],
            [Source::TYPE_AIR_ALERT, 'admin.air-alert.index', 'admin.air-alert.store', '@alert_source'],
        ];

        foreach ($cases as [$type, $indexRoute, $storeRoute, $peer]) {
            $this->actingAs($user)->get(route($indexRoute))
                ->assertOk()
                ->assertSee('Кнопка карты тревог')
                ->assertSee('Показывать кнопку «Мапа тривог України»')
                ->assertSee('Ссылка кнопки');

            $this->actingAs($user)->post(route($storeRoute), $this->sourcePayload($account, [
                'name' => 'Источник '.$type,
                'source_peer' => $peer,
                'map_button_enabled' => '1',
                'map_button_url' => 'https://example.com/maps/'.$type,
            ]))->assertSessionHasNoErrors();

            $rule = Source::query()
                ->where('type', $type)
                ->firstOrFail()
                ->rules()
                ->where('key', 'map_button_url')
                ->firstOrFail();

            $this->assertTrue($rule->is_active);
            $this->assertSame('https://example.com/maps/'.$type, data_get($rule->value, 'value'));
        }
    }

    public function test_disabling_map_button_is_saved_for_the_source(): void
    {
        $user = User::factory()->create();
        $account = $this->createAccount();
        $source = Source::query()->create([
            'technical_account_id' => $account->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'Новости',
            'source_peer' => '@source',
            'destination_peer' => '@SkyGuardianUa',
        ]);
        $source->rules()->create([
            'key' => 'map_button_url',
            'value' => ['value' => 'https://skyguardian.pp.ua/'],
            'is_active' => true,
            'priority' => 80,
        ]);

        $this->actingAs($user)->put(
            route('admin.news.update', $source),
            $this->sourcePayload($account, [
                'form_context' => 'source-'.$source->id,
                'name' => $source->name,
                'source_peer' => $source->source_peer,
                'map_button_enabled' => '0',
                'reset_cursor' => '0',
            ]),
        )->assertSessionHasNoErrors();

        $this->assertFalse($source->rules()->where('key', 'map_button_url')->firstOrFail()->is_active);
    }

    public function test_processor_adds_custom_button_to_each_new_source_post(): void
    {
        $account = $this->createAccount();
        $source = Source::query()->create([
            'technical_account_id' => $account->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'Новости',
            'source_peer' => '@source',
            'destination_peer' => '@SkyGuardianUa',
            'is_active' => true,
            'last_message_id' => 10,
        ]);
        $source->rules()->createMany([
            ['key' => 'copy_mode', 'value' => ['value' => 'original'], 'is_active' => true, 'priority' => 10],
            ['key' => 'map_button_url', 'value' => ['value' => 'https://example.com/custom-map'], 'is_active' => true, 'priority' => 80],
        ]);
        $this->createDestinationBot();

        Http::fake(fn (Request $request) => Http::response([
            'ok' => true,
            'result' => ['message_id' => (int) ($request['message_id'] ?? 0)],
        ]));

        $settings = [
            'copy_mode' => 'original',
            'strip_links' => false,
            'strip_hashtags' => false,
            'strip_mentions' => false,
            'remove_phrases' => [],
            'footer_html' => '',
            'blocked_keywords' => [],
            'resume_partial' => null,
        ];
        $telethon = Mockery::mock(TelethonClient::class);
        $telethon->shouldReceive('call')->once()->ordered()->with('fetch_messages', Mockery::type(TechnicalAccount::class), [
            'peer' => '@source',
            'min_id' => 10,
            'limit' => 100,
        ])->andReturn(['messages' => [
            ['id' => 11, 'text' => 'one', 'grouped_id' => null],
            ['id' => 12, 'text' => 'two', 'grouped_id' => null],
        ]]);

        foreach ([[11, 901], [12, 902]] as [$sourceMessageId, $destinationMessageId]) {
            $expectedPayload = [
                'source_peer' => '@source',
                'destination_peer' => '@SkyGuardianUa',
                'message_ids' => [$sourceMessageId],
                'settings' => $settings,
            ];
            $telethon->shouldReceive('call')->once()->ordered()->with(
                'copy_messages',
                Mockery::type(TechnicalAccount::class),
                Mockery::on(function (array $payload) use ($expectedPayload): bool {
                    $requestId = $payload['request_id'] ?? null;
                    unset($payload['request_id']);

                    return $payload === $expectedPayload
                        && is_string($requestId)
                        && preg_match('/^[a-f0-9]{64}$/', $requestId) === 1;
                }),
            )->andReturn([
                'copied_count' => 1,
                'failed_count' => 0,
                'last_processed_id' => $sourceMessageId,
                'partial_delivery' => null,
                'destination_message_ids' => [$destinationMessageId],
            ]);
        }

        $result = (new SourceProcessor($telethon, new OperationGate, new SourceScheduler))->process($source);

        $this->assertSame(2, $result['messages_copied']);
        $this->assertSame(12, $source->fresh()->last_message_id);
        $this->assertNull($source->fresh()->last_error);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame([901, 902], $requests
            ->map(static fn (array $record): int => (int) $record[0]['message_id'])
            ->all());

        foreach ($requests as [$request]) {
            $this->assertStringEndsWith('/editMessageReplyMarkup', $request->url());
            $button = $request['reply_markup']['inline_keyboard'][0][0] ?? null;
            $this->assertSame('🗺 Мапа тривог України', $button['text'] ?? null);
            $this->assertSame('https://example.com/custom-map', $button['url'] ?? null);
        }
    }

    private function sourcePayload(TechnicalAccount $account, array $overrides = []): array
    {
        return array_merge([
            'form_context' => 'source-create',
            'name' => 'Источник',
            'technical_account_id' => $account->id,
            'source_peer' => '@source',
            'destination_peer' => '@SkyGuardianUa',
            'check_interval' => 60,
            'check_interval_unit' => 'seconds',
            'is_active' => '1',
            'copy_mode' => 'original',
            'strip_links' => '0',
            'strip_hashtags' => '0',
            'strip_mentions' => '0',
            'remove_phrases' => '',
            'footer_html' => '',
            'blocked_keywords_enabled' => '0',
            'blocked_keywords' => '',
            'map_button_enabled' => '0',
            'map_button_url' => 'https://skyguardian.pp.ua/',
        ], $overrides);
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

    private function createDestinationBot(): GroupChannelBot
    {
        return GroupChannelBot::query()->create([
            'bot_name' => 'Alert Bot',
            'bot_token' => '123456:test-token',
            'token_fingerprint' => hash('sha256', '123456:test-token'),
            'webhook_secret' => str_repeat('a', 48),
            'admin_id' => '100500',
            'group_name' => 'SkyGuardianUa',
            'group_link' => 'https://t.me/SkyGuardianUa',
            'chat_type' => 'channel',
            'chat_id' => '-1001234567890',
            'is_active' => true,
        ]);
    }
}
