<?php

namespace Tests\Feature;

use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
use App\Models\User;
use App\Services\TelegramAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TelegramAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_account_reopens_modal_at_connection_step(): void
    {
        $user = User::factory()->create();
        $api = $this->createApi();

        $response = $this->actingAs($user)->post(route('admin.telegram.accounts.store'), [
            'form_context' => 'account-create',
            'name' => 'Новый технический аккаунт',
            'auth_method' => 'phone',
            'phone' => '+380671234567',
            'telegram_api_id' => $api->id,
            'is_active' => '1',
        ]);

        $account = TechnicalAccount::query()->firstOrFail();

        $response->assertRedirect(route('admin.telegram.index'))
            ->assertSessionHas('open_account_id', $account->id);

        $this->actingAs($user)
            ->withSession(['open_account_id' => $account->id])
            ->get(route('admin.telegram.index'))
            ->assertOk()
            ->assertSee('data-open-modal-on-load="account-edit-'.$account->id.'"', false)
            ->assertSee('Запросить код');
    }

    public function test_code_confirmation_keeps_modal_open_when_2fa_is_required(): void
    {
        $user = User::factory()->create();
        $account = TechnicalAccount::query()->create([
            'telegram_api_id' => $this->createApi()->id,
            'name' => 'Аккаунт с 2FA',
            'auth_method' => 'phone',
            'phone' => '+380671234567',
            'status' => 'awaiting_code',
            'is_active' => true,
        ]);

        $service = Mockery::mock(TelegramAuthService::class);
        $service->shouldReceive('signIn')
            ->once()
            ->andReturnUsing(function (TechnicalAccount $passedAccount): TechnicalAccount {
                $passedAccount->forceFill(['status' => 'awaiting_password'])->save();

                return $passedAccount->refresh();
            });
        $this->app->instance(TelegramAuthService::class, $service);

        $response = $this->actingAs($user)->post(route('admin.telegram.accounts.sign-in', $account), [
            'form_context' => 'account-'.$account->id,
            'code' => '12345',
        ]);

        $response->assertRedirect(route('admin.telegram.index'))
            ->assertSessionHas('open_account_id', $account->id);

        $this->actingAs($user)
            ->withSession(['open_account_id' => $account->id])
            ->get(route('admin.telegram.index'))
            ->assertOk()
            ->assertSee('Пароль 2FA')
            ->assertSee('Подтвердить пароль');
    }

    public function test_successful_code_confirmation_returns_to_settings_without_reopening_modal(): void
    {
        $user = User::factory()->create();
        $account = TechnicalAccount::query()->create([
            'telegram_api_id' => $this->createApi()->id,
            'name' => 'Аккаунт без 2FA',
            'auth_method' => 'phone',
            'phone' => '+380671234567',
            'status' => 'awaiting_code',
            'is_active' => true,
        ]);

        $service = Mockery::mock(TelegramAuthService::class);
        $service->shouldReceive('signIn')
            ->once()
            ->andReturnUsing(function (TechnicalAccount $passedAccount): TechnicalAccount {
                $passedAccount->forceFill(['status' => 'connected'])->save();

                return $passedAccount->refresh();
            });
        $this->app->instance(TelegramAuthService::class, $service);

        $response = $this->actingAs($user)->post(route('admin.telegram.accounts.sign-in', $account), [
            'form_context' => 'account-'.$account->id,
            'code' => '12345',
        ]);

        $response->assertRedirect(route('admin.telegram.index'))
            ->assertSessionMissing('open_account_id')
            ->assertSessionHas('toast');
    }

    private function createApi(): TelegramApi
    {
        return TelegramApi::query()->create([
            'name' => 'Test API',
            'api_id' => random_int(100000, 999999),
            'api_hash' => '1234567890abcdef1234567890abcdef',
            'is_active' => true,
        ]);
    }
}
