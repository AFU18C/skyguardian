<?php

namespace App\Services;

use App\Models\TechnicalAccount;
use RuntimeException;

class GroupChannelTelethonClient
{
    public function call(
        string $action,
        TechnicalAccount $account,
        array $payload = [],
        ?int $timeoutSeconds = null,
    ): array {
        $api = $account->telegramApi;

        if (! $api || ! $api->is_active) {
            throw new RuntimeException('Telegram API не найден или отключён.');
        }

        $request = json_encode([
            'action' => $action,
            'api_id' => $api->api_id,
            'api_hash' => $api->api_hash,
            'session' => $account->session,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR)."\n";

        $timeout = max(
            1,
            $timeoutSeconds ?? (int) config('skyguardian.group_channel_telethon.timeout_seconds', 180),
        );
        $socket = @stream_socket_client(
            sprintf(
                'tcp://%s:%d',
                config('skyguardian.group_channel_telethon.host'),
                config('skyguardian.group_channel_telethon.port'),
            ),
            $errno,
            $error,
            $timeout,
        );

        if (! is_resource($socket)) {
            throw new RuntimeException("Отдельный Telethon-процесс модуля групп и каналов недоступен: {$error} ({$errno}).");
        }

        try {
            stream_set_timeout($socket, $timeout);

            if (fwrite($socket, $request) === false) {
                throw new RuntimeException('Не удалось отправить запрос отдельному Telethon-процессу.');
            }

            $response = fgets($socket);
            $metadata = stream_get_meta_data($socket);

            if ($response === false) {
                throw new RuntimeException(($metadata['timed_out'] ?? false)
                    ? 'Истекло время ожидания отдельного Telethon-процесса.'
                    : 'Отдельный Telethon-процесс не вернул ответ.');
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
