<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Services\OperationGate;
use App\Services\SourceProcessor;
use App\Services\SourceScheduler;
use App\Services\SourceService;
use App\Services\TechnicalAccountService;
use App\Services\TelethonClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SkyGuardianCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_telegram_values_are_encrypted(): void
    {
        $api = $this->createApi();
        $account = $this->createAccount($api, ['session' => 'secret-session']);

        $this->assertNotSame('secret-hash', DB::table('telegram_apis')->where('id', $api->id)->value('api_hash'));
        $this->assertNotSame('secret-session', DB::table('technical_accounts')->where('id', $account->id)->value('session'));
        $this->assertSame('secret-hash', $api->fresh()->api_hash);
        $this->assertSame('secret-session', $account->fresh()->session);
    }

    public function test_operation_gate_enforces_per_account_limit(): void
    {
        config()->set('skyguardian.limits.account_concurrent_operations', 2);
        $account = $this->createAccount($this->createApi());
        $gate = new OperationGate();

        $gate->acquire('one', $account);
        $gate->acquire('two', $account);

        $this->expectException(RuntimeException::class);
        $gate->acquire('three', $account);
    }

    public function test_operation_gate_enforces_global_limit(): void
    {
        config()->set('skyguardian.limits.global_concurrent_operations', 5);
        $gate = new OperationGate();

        for ($i = 0; $i < 5; $i++) {
            $gate->acquire('operation-'.$i);
        }

        $this->expectException(RuntimeException::class);
        $gate->acquire('sixth');
    }

    public function test_manual_account_check_updates_only_account_health_data(): void
    {
        $account = $this->createAccount($this->createApi(), ['session' => 'old-session']);
        $telethon = Mockery::mock(TelethonClient::class);
        $telethon->shouldReceive('call')->once()->with('check', Mockery::type(TechnicalAccount::class))->andReturn([
            'session' => 'new-session',
            'user' => [
                'id' => 123,
                'username' => 'guardian',
                'first_name' => 'Sky',
                'last_name' => 'Guardian',
                'phone' => '380000000000',
            ],
        ]);

        $result = (new TechnicalAccountService($telethon, new OperationGate()))->manualCheck($account);

        $this->assertSame('connected', $result->status);
        $this->assertSame(123, $result->telegram_user_id);
        $this->assertSame('guardian', $result->username);
        $this->assertSame('new-session', $result->session);
        $this->assertNotNull($result->last_manual_check_at);
        $this->assertNotNull($result->last_success_at);
    }

    public function test_manual_source_check_does_not_change_last_message_id(): void
    {
        $account = $this->createAccount($this->createApi());
        $source = Source::query()->create([
            'technical_account_id' => $account->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'Новости',
            'source_peer' => '@source',
            'last_message_id' => 77,
        ]);

        $telethon = Mockery::mock(TelethonClient::class);
        $telethon->shouldReceive('call')->once()->with('check_peer', Mockery::type(TechnicalAccount::class), [
            'peer' => '@source',
        ])->andReturn(['peer' => ['id' => 1]]);

        $result = (new SourceService($telethon, new OperationGate()))->manualCheck($source);

        $this->assertSame('available', $result->status);
        $this->assertSame(77, $result->last_message_id);
        $this->assertNotNull($result->last_manual_check_at);
    }

    public function test_automatic_processing_does_not_change_manual_status_or_check_time(): void
    {
        $manualTime = now()->subHour()->startOfSecond();
        $account = $this->createAccount($this->createApi());
        $source = Source::query()->create([
            'technical_account_id' => $account->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'Новости',
            'source_peer' => '@source',
            'destination_peer' => '@destination',
            'is_active' => true,
            'last_message_id' => 10,
            'status' => 'available',
            'last_manual_check_at' => $manualTime,
        ]);

        $telethon = Mockery::mock(TelethonClient::class);
        $telethon->shouldReceive('call')->once()->with('fetch_messages', Mockery::type(TechnicalAccount::class), [
            'peer' => '@source',
            'min_id' => 10,
            'limit' => 100,
        ])->andReturn([
            'messages' => [
                ['id' => 11, 'text' => 'one'],
                ['id' => 12, 'text' => 'two'],
            ],
        ]);
        $telethon->shouldReceive('call')->once()->with('relay_messages', Mockery::type(TechnicalAccount::class), [
            'source_peer' => '@source',
            'destination_peer' => '@destination',
            'message_ids' => [11, 12],
        ])->andReturn(['forwarded_ids' => [11, 12]]);

        $processor = new SourceProcessor($telethon, new OperationGate(), new SourceScheduler());
        $processor->process($source);
        $source->refresh();

        $this->assertSame('available', $source->status);
        $this->assertTrue($source->last_manual_check_at->equalTo($manualTime));
        $this->assertSame(12, $source->last_message_id);
        $this->assertNotNull($source->next_check_at);
    }

    public function test_account_and_source_limits_are_enforced(): void
    {
        config()->set('skyguardian.limits.technical_accounts', 2);
        config()->set('skyguardian.limits.sources', 2);
        $api = $this->createApi();

        $first = $this->createAccount($api, ['name' => 'One']);
        $this->createAccount($api, ['name' => 'Two']);

        try {
            $this->createAccount($api, ['name' => 'Three']);
            $this->fail('Technical account limit was not enforced.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        Source::query()->create([
            'technical_account_id' => $first->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'One',
            'source_peer' => '@one',
        ]);
        Source::query()->create([
            'technical_account_id' => $first->id,
            'type' => Source::TYPE_AIR_ALERT,
            'name' => 'Two',
            'source_peer' => '@two',
        ]);

        $this->expectException(RuntimeException::class);
        Source::query()->create([
            'technical_account_id' => $first->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'Three',
            'source_peer' => '@three',
        ]);
    }

    private function createApi(): TelegramApi
    {
        return TelegramApi::query()->create([
            'name' => 'Main API',
            'api_id' => random_int(100000, 999999),
            'api_hash' => 'secret-hash',
        ]);
    }

    private function createAccount(TelegramApi $api, array $attributes = []): TechnicalAccount
    {
        return TechnicalAccount::query()->create(array_merge([
            'telegram_api_id' => $api->id,
            'name' => 'Account',
            'phone' => '+380000000000',
        ], $attributes));
    }
}
