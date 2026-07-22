<?php
declare(strict_types=1);

namespace SkyGuardian\Telegram;

final class BotApiClient
{
    public function __construct(private readonly string $token) {}

    public function call(string $method, array $payload = []): array
    {
        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $raw = file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('Telegram request failed.');
        }
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !($result['ok'] ?? false)) {
            throw new \RuntimeException((string) ($result['description'] ?? 'Telegram API error.'));
        }
        return $result;
    }
}
