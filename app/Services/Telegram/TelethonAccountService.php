<?php

namespace App\Services\Telegram;

use App\Models\AlertBotSetting;
use App\Models\TechnicalTelegramAccount;
use RuntimeException;
use Symfony\Component\Process\Process;

class TelethonAccountService
{
    public function isConfigured(): bool
    {
        [$apiId, $apiHash] = $this->credentials();

        return filled($apiId) && filled($apiHash);
    }

    public function sendCode(string $phone, TechnicalTelegramAccount $account): array
    {
        return $this->run(['send-code', '--phone', $phone], $account);
    }

    public function signIn(string $phone, string $code, string $phoneCodeHash, TechnicalTelegramAccount $account, ?string $password = null): array
    {
        $arguments = [
            'sign-in',
            '--phone', $phone,
            '--code', $code,
            '--phone-code-hash', $phoneCodeHash,
        ];

        if (filled($password)) {
            $arguments[] = '--password';
            $arguments[] = $password;
        }

        return $this->run($arguments, $account);
    }

    public function status(TechnicalTelegramAccount $account): array
    {
        return $this->run(['status'], $account);
    }

    public function logout(TechnicalTelegramAccount $account): array
    {
        return $this->run(['logout'], $account);
    }

    private function credentials(): array
    {
        $settings = AlertBotSetting::query()->first();

        return [
            $settings?->telegram_api_id ?: config('services.telegram.api_id'),
            $settings?->telegram_api_hash ?: config('services.telegram.api_hash'),
        ];
    }

    private function run(array $arguments, TechnicalTelegramAccount $account): array
    {
        [$apiId, $apiHash] = $this->credentials();

        if (! filled($apiId) || ! filled($apiHash)) {
            throw new RuntimeException('Вкажіть API ID та App api_hash у налаштуваннях бота.');
        }

        $sessionDirectory = storage_path('app/private/telegram/accounts');
        if (! is_dir($sessionDirectory) && ! mkdir($sessionDirectory, 0770, true) && ! is_dir($sessionDirectory)) {
            throw new RuntimeException('Не вдалося створити каталог Telegram-сесій.');
        }

        $command = array_merge([
            config('services.telegram.python', 'python3'),
            base_path('scripts/telegram_account.py'),
            '--api-id', (string) $apiId,
            '--api-hash', (string) $apiHash,
            '--session', $sessionDirectory.'/'.$account->sessionKey(),
        ], $arguments);

        $process = new Process($command, base_path());
        $process->setTimeout(45);
        $process->run();

        $output = trim($process->getOutput() ?: $process->getErrorOutput());
        $result = json_decode($output, true);

        if (! is_array($result)) {
            throw new RuntimeException($output ?: 'Telethon не повернув відповідь.');
        }

        if (! $process->isSuccessful() || ($result['ok'] ?? false) !== true) {
            throw new RuntimeException($result['message'] ?? 'Помилка підключення до Telegram.');
        }

        return $result;
    }
}
