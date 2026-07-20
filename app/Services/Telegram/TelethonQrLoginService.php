<?php

namespace App\Services\Telegram;

use App\Models\TechnicalTelegramAccount;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class TelethonQrLoginService
{
    public function start(TechnicalTelegramAccount $account, ?string $password = null): string
    {
        $account->loadMissing('telegramApiCredential');
        $api = $account->telegramApiCredential;

        if (! filled($api?->api_id) || ! filled($api?->api_hash)) {
            throw new RuntimeException('Выберите Telegram API для технического аккаунта.');
        }

        $token = Str::random(48);
        $stateFile = $this->stateFile($token);
        $sessionPath = storage_path('app/private/telegram/accounts/'.$account->sessionKey());

        if (! is_dir(dirname($stateFile)) && ! mkdir(dirname($stateFile), 0770, true) && ! is_dir(dirname($stateFile))) {
            throw new RuntimeException('Не удалось создать каталог QR-авторизации.');
        }

        $arguments = [
            config('services.telegram.python', 'python3'),
            base_path('scripts/telegram_qr_login.py'),
            '--api-id', (string) $api->api_id,
            '--api-hash', (string) $api->api_hash,
            '--session', $sessionPath,
            '--state-file', $stateFile,
            '--timeout', '120',
        ];

        if (filled($password)) {
            $arguments[] = '--password';
            $arguments[] = $password;
        }

        $command = implode(' ', array_map('escapeshellarg', $arguments)).' > /dev/null 2>&1 &';
        Process::fromShellCommandline($command, base_path())->mustRun();

        for ($attempt = 0; $attempt < 25; $attempt++) {
            usleep(200000);
            $state = $this->state($token);
            if (in_array($state['status'] ?? null, ['waiting', 'connected', 'error', 'password_required'], true)) {
                return $token;
            }
        }

        throw new RuntimeException('Не удалось получить QR-код от Telegram.');
    }

    public function state(string $token): array
    {
        $path = $this->stateFile($token);
        if (! is_file($path)) {
            return ['status' => 'starting'];
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : ['status' => 'error', 'message' => 'Повреждён файл состояния QR-входа.'];
    }

    public function forget(string $token): void
    {
        $path = $this->stateFile($token);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function stateFile(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            throw new RuntimeException('Некорректный идентификатор QR-входа.');
        }

        return storage_path('app/private/telegram/qr/'.$token.'.json');
    }
}
