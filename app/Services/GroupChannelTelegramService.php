<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GroupChannelTelegramService
{
    public function request(GroupChannelBot $bot, string $method, array $payload = []): mixed
    {
        $response = $this->client($bot)
            ->post($method, $this->normalizePayload($method, $payload));
        $body = $response->json();

        if (! $response->successful() || ! ($body['ok'] ?? false)) {
            throw new RuntimeException($body['description'] ?? 'Ошибка Telegram API: HTTP '.$response->status());
        }

        return $body['result'] ?? [];
    }

    public function upload(
        GroupChannelBot $bot,
        string $method,
        string $field,
        string $storedPath,
        array $payload = [],
    ): array {
        $absolutePath = Storage::disk('local')->path($storedPath);

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Файл публикации не найден: '.$storedPath);
        }

        $request = $this->client($bot)
            ->asMultipart()
            ->attach($field, fopen($absolutePath, 'rb'), basename($absolutePath));
        $response = $request->post($method, $payload);
        $body = $response->json();

        if (! $response->successful() || ! ($body['ok'] ?? false)) {
            throw new RuntimeException($body['description'] ?? 'Ошибка Telegram API: HTTP '.$response->status());
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }

    public function sendMediaGroup(GroupChannelBot $bot, array $items, array $payload = []): array
    {
        if (count($items) < 2 || count($items) > 10) {
            throw new RuntimeException('Альбом должен содержать от 2 до 10 файлов.');
        }

        $request = $this->client($bot)->asMultipart();
        $media = [];

        foreach (array_values($items) as $index => $item) {
            $storedPath = (string) ($item['path'] ?? '');
            $absolutePath = Storage::disk('local')->path($storedPath);

            if (! is_file($absolutePath)) {
                throw new RuntimeException('Файл альбома не найден: '.$storedPath);
            }

            $field = 'media_'.$index;
            $request = $request->attach($field, fopen($absolutePath, 'rb'), basename($absolutePath));
            $media[] = array_filter([
                'type' => ($item['type'] ?? 'photo') === 'video' ? 'video' : 'photo',
                'media' => 'attach://'.$field,
                'caption' => $item['caption'] ?? null,
                'parse_mode' => $item['parse_mode'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        $response = $request->post('sendMediaGroup', array_merge($payload, [
            'media' => json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
        $body = $response->json();

        if (! $response->successful() || ! ($body['ok'] ?? false)) {
            throw new RuntimeException($body['description'] ?? 'Ошибка Telegram API: HTTP '.$response->status());
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }

    private function normalizePayload(string $method, array $payload): array
    {
        if ($method !== 'sendPoll' || ! isset($payload['options'])) {
            return $payload;
        }

        $options = $payload['options'];
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }

        $payload['options'] = json_encode(
            collect($options)
                ->map(fn (mixed $option): array => is_array($option)
                    ? $option
                    : ['text' => trim((string) $option)])
                ->filter(fn (array $option): bool => ($option['text'] ?? '') !== '')
                ->values()
                ->all(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $payload;
    }

    private function client(GroupChannelBot $bot): PendingRequest
    {
        return Http::baseUrl('https://api.telegram.org/bot'.$bot->bot_token)
            ->acceptJson()
            ->timeout(30);
    }
}
