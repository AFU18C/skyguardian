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
        $response = $this->client($bot)->post($method, $payload)->throw()->json();

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException($response['description'] ?? 'Ошибка Telegram API');
        }

        return $response['result'] ?? [];
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
        $response = $request->post($method, $payload)->throw()->json();

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException($response['description'] ?? 'Ошибка Telegram API');
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
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
        ]))->throw()->json();

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException($response['description'] ?? 'Ошибка Telegram API');
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private function client(GroupChannelBot $bot): PendingRequest
    {
        return Http::baseUrl('https://api.telegram.org/bot'.$bot->bot_token)
            ->acceptJson()
            ->timeout(30);
    }
}
