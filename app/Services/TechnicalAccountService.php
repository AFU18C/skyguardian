<?php

namespace App\Services;

use App\Models\TechnicalAccount;
use RuntimeException;
use Throwable;

class TechnicalAccountService
{
    public function __construct(
        private readonly TelethonClient $telethon,
        private readonly OperationGate $gate,
    ) {}

    public function manualCheck(TechnicalAccount $account): TechnicalAccount
    {
        if (! $account->is_active) {
            throw new RuntimeException('Технический аккаунт отключён.');
        }

        $token = $this->gate->acquire('technical-account.manual-check', $account);

        try {
            $result = $this->telethon->call('check', $account);
            $user = $result['user'] ?? [];

            $account->forceFill([
                'session' => $result['session'] ?? $account->session,
                'phone' => $user['phone'] ?? $account->phone,
                'telegram_user_id' => $user['id'] ?? null,
                'username' => $user['username'] ?? null,
                'first_name' => $user['first_name'] ?? null,
                'last_name' => $user['last_name'] ?? null,
                'status' => 'connected',
                'last_error' => null,
                'last_manual_check_at' => now(),
                'last_success_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $account->forceFill([
                'status' => 'error',
                'last_error' => $e->getMessage(),
                'last_manual_check_at' => now(),
            ])->save();

            throw $e;
        } finally {
            $this->gate->release($token);
        }

        return $account->refresh();
    }
}
