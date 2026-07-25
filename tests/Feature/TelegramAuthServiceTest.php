<?php

namespace Tests\Feature;

use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Services\OperationGate;
use App\Services\TelegramAuthService;
use App\Services\TelethonClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TelegramAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_authorization_supports_code_and_two_factor_password(): void
    {
        $account = $this->createAccount();
        $telethon = Mockery::mock(TelethonClient::class);

        $telethon->shouldReceive('call')->once()->with('send_code', Mockery::type(TechnicalAccount::class))->andReturn([
            'session' => 'code-session',
            'phone_code_hash' => 'phone-hash',
        ]);
        $telethon->shouldReceive('call')->once()->with('sign_in', Mockery::type(TechnicalAccount::class), [
            'code' => '12345',
            'phone_code_hash' => 'phone-hash',
        ])->andReturn([
            'session' => 'password-session',
            'requires_password' => true,
        ]);
        $telethon->shouldReceive('call')->once()->with('sign_in_password', Mockery::type(TechnicalAccount::class), [
            'password' => 'secret-password',
        ])->andReturn([
            'session' => 'authorized-session',
            'user' => [
                'id' => 555,
                'username' => 'skyguardian',
                'first_name' => 'Sky',
                'last_name' => 'Guardian',
                'phone' => '380000000000',
            ],
        ]);

        $service = new TelegramAuthService($telethon, new OperationGate);

        $account = $service->sendCode($account);
        $this->assertSame('awaiting_code', $account->status);
        $this->assertSame('phone-hash', $account->auth_data['phone_code_hash']);

        $account = $service->signIn($account, '12345');
        $this->assertSame('awaiting_password', $account->status);
        $this->assertSame('password-session', $account->session);

        $account = $service->signInPassword($account, 'secret-password');
        $this->assertSame('connected', $account->status);
        $this->assertSame(555, $account->telegram_user_id);
        $this->assertSame('authorized-session', $account->session);
        $this->assertNull($account->auth_data);
    }

    public function test_qr_authorization_stores_temporary_state_and_completes(): void
    {
        $account = $this->createAccount();
        $telethon = Mockery::mock(TelethonClient::class);

        $telethon->shouldReceive('call')->once()->with('qr_start', Mockery::type(TechnicalAccount::class))->andReturn([
            'session' => 'qr-session',
            'token' => 'qr-token',
            'url' => 'tg://login?token=test',
            'expires_at' => now()->addMinute()->timestamp,
        ]);
        $telethon->shouldReceive('call')->once()->with('qr_wait', Mockery::type(TechnicalAccount::class), [
            'token' => 'qr-token',
            'timeout' => 20,
        ])->andReturn([
            'status' => 'connected',
            'session' => 'authorized-session',
            'user' => [
                'id' => 777,
                'username' => 'qr_guardian',
                'first_name' => 'QR',
                'last_name' => 'Guardian',
                'phone' => null,
            ],
        ]);

        $service = new TelegramAuthService($telethon, new OperationGate);
        $started = $service->startQr($account);

        $this->assertSame('tg://login?token=test', $started['url']);
        $this->assertSame('awaiting_qr', $started['account']->status);

        $result = $service->waitQr($started['account'], 20);

        $this->assertSame('connected', $result['status']);
        $this->assertSame(777, $result['account']->telegram_user_id);
        $this->assertNull($result['account']->auth_data);
    }

    private function createAccount(): TechnicalAccount
    {
        $api = TelegramApi::query()->create([
            'name' => 'Main API',
            'api_id' => 123456,
            'api_hash' => 'secret-hash',
        ]);

        return TechnicalAccount::query()->create([
            'telegram_api_id' => $api->id,
            'name' => 'Account',
            'phone' => '+380000000000',
        ]);
    }
}
