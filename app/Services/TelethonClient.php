<?php

namespace App\Services;

use App\Models\TechnicalAccount;
use RuntimeException;

class TelethonClient
{
    public function call(string $action, TechnicalAccount $account, array $payload = []): array
    {
        $api = $account->telegramApi;

        if (! $api || ! $api->is_active) {
            throw new RuntimeException('Telegram API не найден или отключён.');
        }

        $request = json_encode([
            'action' => $action,
            'account_key' => (string) $account->id,
            'api_id' => $api->api_id,
            'api_hash' => $api->api_hash,
            'session' => $account->session,
            'phone' => $account->phone,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR)."\n";

        $timeout = max(1, (int) config('skyguardian.telethon.timeout_seconds', 60));
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', config('skyguardian.telethon.host'), config('skyguardian.telethon.port')),
            $errno,
            $error,
            $timeout,
        );

        if (! is_resource($socket)) {
            throw new RuntimeException("Telethon daemon недоступен: {$error} ({$errno}).");
        }

        try {
            stream_set_timeout($socket, $timeout);

            if (fwrite($socket, $request) === false) {
                throw new RuntimeException('Не удалось отправить запрос Telethon daemon.');
            }

            $response = fgets($socket);
            $metadata = stream_get_meta_data($socket);

            if ($response === false) {
                throw new RuntimeException(($metadata['timed_out'] ?? false)
                    ? 'Истекло время ожидания ответа Telethon daemon.'
                    : 'Telethon daemon не вернул ответ.');
            }
        } finally {
            fclose($socket);
        }

        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);

        if (! ($decoded['ok'] ?? false)) {
            throw new RuntimeException((string) ($decoded['error'] ?? 'Неизвестная ошибка Telegram.'));
        }

        return $decoded;
    }
}
