<?php

namespace App\Services;

use App\Models\TechnicalAccount;
use RuntimeException;

class TelethonClient
{
    public function call(string $action, TechnicalAccount $account, array $payload = []): array
    {
        $api = $account->telegramApi;
        $request = json_encode([
            'action' => $action,
            'account_key' => (string) $account->id,
            'api_id' => $api->api_id,
            'api_hash' => $api->api_hash,
            'session' => $account->session,
            'phone' => $account->phone,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR)."\n";

        $timeout = config('skyguardian.telethon.timeout_seconds', 60);
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', config('skyguardian.telethon.host'), config('skyguardian.telethon.port')),
            $errno,
            $error,
            $timeout,
        );

        if (! is_resource($socket)) {
            throw new RuntimeException("Telethon daemon недоступен: {$error} ({$errno}).");
        }

        stream_set_timeout($socket, $timeout);
        fwrite($socket, $request);
        $response = fgets($socket);
        fclose($socket);

        if ($response === false) {
            throw new RuntimeException('Telethon daemon не вернул ответ.');
        }

        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (! ($decoded['ok'] ?? false)) {
            throw new RuntimeException((string) ($decoded['error'] ?? 'Неизвестная ошибка Telegram.'));
        }

        return $decoded;
    }
}
