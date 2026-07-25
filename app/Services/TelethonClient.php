<?php

namespace App\Services;

use App\Models\TechnicalAccount;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class TelethonClient
{
    public function call(string $action, TechnicalAccount $account, array $payload = []): array
    {
        $api = $account->telegramApi;

        $input = [
            'action' => $action,
            'api_id' => $api->api_id,
            'api_hash' => $api->api_hash,
            'session' => $account->session,
            'phone' => $account->phone,
            'payload' => $payload,
        ];

        $result = Process::timeout(config('skyguardian.telethon.timeout_seconds', 60))
            ->input(json_encode($input, JSON_THROW_ON_ERROR))
            ->run([
                config('skyguardian.telethon.python'),
                config('skyguardian.telethon.worker'),
            ]);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'Telethon worker завершился с ошибкой.');
        }

        $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        if (! ($decoded['ok'] ?? false)) {
            throw new RuntimeException((string) ($decoded['error'] ?? 'Неизвестная ошибка Telegram.'));
        }

        return $decoded;
    }
}
