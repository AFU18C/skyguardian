<?php

namespace App\Services\Telegram;

use App\Models\AlertBotSetting;
use RuntimeException;
use Symfony\Component\Process\Process;

class TelethonAccountService
{
    public function isConfigured(): bool
    {
        [$apiId, $apiHash] = $this->credentials();

        return filled($apiId) && filled($apiHash);
    }

    public function sendCode(string $phone): array
    {
        return $this->run(['send-code', '--phone', $phone]);
    }

    public function signIn(string $phone, string $code, string $phoneCodeHash, ?string $password = null): array
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

        return $this->run($arguments);
    }

    public function status(): array
    {
        return $this->run(['status']);
    }

    public function logout(): array
    {
        return $this->run(['logout']);
    }

    private function credentials(): array
    {
        $settings = AlertBotSetting::query()->first();

        return [
            $settings?->telegram_api_id ?: config('services.telegram.api_id'),
            $settings?->telegram_api_hash ?: config('services.telegram.api_hash'),
        ];
    }

    private function run(array $arguments): array
    {
        [$apiId, $apiHash] = $this->credentials();

        if (! filled($apiId) || ! filled($apiHash)) {
            throw new RuntimeException('Вкажіть API ID та App api_hash у налаштуваннях бота.');
        }

        $command = array_merge([
            config('services.telegram.python', 'python3'),
            base_path('scripts/telegram_account.py'),
            '--api-id', (string) $apiId,
            '--api-hash', (string) $apiHash,
            '--session', storage_path('app/private/telegram/technical'),
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
