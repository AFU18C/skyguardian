<?php
declare(strict_types=1);

namespace SkyGuardian\Notification;

final class TelegramBotSender
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function __invoke(string $botToken, string $chatId, string $text): void
    {
        $handle = curl_init('https://api.telegram.org/bot' . $botToken . '/sendMessage');
        if ($handle === false) {
            throw new \RuntimeException('Cannot initialize Telegram request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => 'true',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new \RuntimeException('Telegram delivery network error.');
        }

        $data = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($data) || ($data['ok'] ?? false) !== true) {
            $description = is_array($data) ? trim((string) ($data['description'] ?? '')) : '';
            throw new \RuntimeException($description !== '' ? $description : 'Telegram rejected notification.');
        }
    }
}
