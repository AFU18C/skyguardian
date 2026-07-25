<?php

namespace App\Services;

use App\Models\TechnicalAccount;
use RuntimeException;
use Throwable;

class TelegramAuthService
{
    public function __construct(
        private readonly TelethonClient $telethon,
        private readonly OperationGate $gate,
    ) {}

    public function sendCode(TechnicalAccount $account): TechnicalAccount
    {
        if (! $account->phone) {
            throw new RuntimeException('Не указан номер телефона.');
        }

        return $this->run($account, 'telegram.send-code', function () use ($account): void {
            $result = $this->telethon->call('send_code', $account);

            $account->forceFill([
                'auth_method' => 'phone',
                'session' => $result['session'] ?? $account->session,
                'auth_data' => ['phone_code_hash' => $result['phone_code_hash']],
                'auth_expires_at' => now()->addMinutes(10),
                'status' => 'awaiting_code',
                'last_error' => null,
            ])->save();
        });
    }

    public function signIn(TechnicalAccount $account, string $code): TechnicalAccount
    {
        $authData = $account->auth_data ?? [];
        $phoneCodeHash = $authData['phone_code_hash'] ?? null;

        if (! $phoneCodeHash || ($account->auth_expires_at && $account->auth_expires_at->isPast())) {
            throw new RuntimeException('Код авторизации истёк. Запросите новый код.');
        }

        return $this->run($account, 'telegram.sign-in', function () use ($account, $code, $phoneCodeHash): void {
            $result = $this->telethon->call('sign_in', $account, [
                'code' => $code,
                'phone_code_hash' => $phoneCodeHash,
            ]);

            $account->session = $result['session'] ?? $account->session;

            if ($result['requires_password'] ?? false) {
                $account->forceFill([
                    'status' => 'awaiting_password',
                    'last_error' => null,
                ])->save();
                return;
            }

            $this->completeAuthorization($account, $result);
        });
    }

    public function signInPassword(TechnicalAccount $account, string $password): TechnicalAccount
    {
        return $this->run($account, 'telegram.sign-in-password', function () use ($account, $password): void {
            $result = $this->telethon->call('sign_in_password', $account, ['password' => $password]);
            $this->completeAuthorization($account, $result);
        });
    }

    public function startQr(TechnicalAccount $account): array
    {
        $token = $this->gate->acquire('telegram.qr-start', $account);

        try {
            $result = $this->telethon->call('qr_start', $account);

            $account->forceFill([
                'auth_method' => 'qr',
                'session' => $result['session'] ?? $account->session,
                'auth_data' => [
                    'qr_token' => $result['token'],
                    'qr_url' => $result['url'],
                ],
                'auth_expires_at' => now()->setTimestamp((int) $result['expires_at']),
                'status' => 'awaiting_qr',
                'last_error' => null,
            ])->save();

            return [
                'url' => $result['url'],
                'expires_at' => $account->auth_expires_at,
                'account' => $account->refresh(),
            ];
        } catch (Throwable $e) {
            $this->markError($account, $e);
            throw $e;
        } finally {
            $this->gate->release($token);
        }
    }

    public function waitQr(TechnicalAccount $account, int $timeout = 20): array
    {
        $qrToken = ($account->auth_data ?? [])['qr_token'] ?? null;

        if (! $qrToken) {
            throw new RuntimeException('QR-сессия не найдена.');
        }

        $token = $this->gate->acquire('telegram.qr-wait', $account, 60);

        try {
            $result = $this->telethon->call('qr_wait', $account, [
                'token' => $qrToken,
                'timeout' => $timeout,
            ]);

            $status = $result['status'] ?? 'pending';

            if ($status === 'connected') {
                $this->completeAuthorization($account, $result);
            } elseif ($status === 'awaiting_password') {
                $account->forceFill([
                    'session' => $result['session'] ?? $account->session,
                    'status' => 'awaiting_password',
                    'auth_data' => null,
                    'auth_expires_at' => null,
                    'last_error' => null,
                ])->save();
            } elseif ($status === 'expired') {
                $account->forceFill([
                    'status' => 'qr_expired',
                    'auth_data' => null,
                    'auth_expires_at' => null,
                    'last_error' => 'Срок действия QR-кода истёк.',
                ])->save();
            }

            return ['status' => $status, 'account' => $account->refresh()];
        } catch (Throwable $e) {
            $this->markError($account, $e);
            throw $e;
        } finally {
            $this->gate->release($token);
        }
    }

    private function run(TechnicalAccount $account, string $operation, callable $callback): TechnicalAccount
    {
        $token = $this->gate->acquire($operation, $account);

        try {
            $callback();
            return $account->refresh();
        } catch (Throwable $e) {
            $this->markError($account, $e);
            throw $e;
        } finally {
            $this->gate->release($token);
        }
    }

    private function completeAuthorization(TechnicalAccount $account, array $result): void
    {
        $user = $result['user'] ?? [];

        $account->forceFill([
            'session' => $result['session'] ?? $account->session,
            'phone' => $user['phone'] ?? $account->phone,
            'telegram_user_id' => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'auth_data' => null,
            'auth_expires_at' => null,
            'status' => 'connected',
            'last_error' => null,
            'last_success_at' => now(),
        ])->save();
    }

    private function markError(TechnicalAccount $account, Throwable $e): void
    {
        $account->forceFill([
            'status' => 'error',
            'last_error' => $e->getMessage(),
        ])->save();
    }
}
