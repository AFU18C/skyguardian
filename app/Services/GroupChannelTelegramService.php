<?php

namespace App\Services;

use App\Exceptions\TelegramDeliveryUncertainException;
use App\Models\GroupChannelBot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GroupChannelTelegramService
{
    public function request(GroupChannelBot $bot, string $method, array $payload = []): mixed
    {
        try {
            $response = $this->client($bot)
                ->post($method, $this->normalizePayload($bot, $method, $payload));
        } catch (ConnectionException $e) {
            if ($this->createsMessage($method)) {
                throw new TelegramDeliveryUncertainException(previous: $e);
            }

            throw new RuntimeException('Не удалось подключиться к Telegram API.', 0, $e);
        }
        $body = $response->json();

        if ($response->serverError()) {
            if ($this->createsMessage($method)) {
                throw new TelegramDeliveryUncertainException('Telegram вернул временную серверную ошибку. Сообщение могло быть принято; автоматический повтор заблокирован.');
            }

            throw new RuntimeException('Telegram API временно недоступен: HTTP '.$response->status());
        }
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
        try {
            $response = $request->post($method, $payload);
        } catch (ConnectionException $e) {
            throw new TelegramDeliveryUncertainException(previous: $e);
        }
        $body = $response->json();

        if ($response->serverError()) {
            throw new TelegramDeliveryUncertainException('Telegram вернул временную серверную ошибку после загрузки. Публикация могла быть принята; автоматический повтор заблокирован.');
        }
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

        try {
            $response = $request->post('sendMediaGroup', array_merge($payload, [
                'media' => json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]));
        } catch (ConnectionException $e) {
            throw new TelegramDeliveryUncertainException(previous: $e);
        }
        $body = $response->json();

        if ($response->serverError()) {
            throw new TelegramDeliveryUncertainException('Telegram вернул временную серверную ошибку после отправки альбома. Публикация могла быть принята; автоматический повтор заблокирован.');
        }
        if (! $response->successful() || ! ($body['ok'] ?? false)) {
            throw new RuntimeException($body['description'] ?? 'Ошибка Telegram API: HTTP '.$response->status());
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }

    protected function normalizePayload(GroupChannelBot $bot, string $method, array $payload): array
    {
        if ($this->shouldConfigureAlertMapButton()) {
            $payload = $this->withAlertMapButton($bot, $method, $payload);
        }

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

    protected function shouldConfigureAlertMapButton(): bool
    {
        return true;
    }

    private function withAlertMapButton(GroupChannelBot $bot, string $method, array $payload): array
    {
        if (! in_array($method, ['sendMessage', 'editMessageText'], true)) {
            return $payload;
        }

        $text = $payload['text'] ?? null;

        if (! is_string($text) || ! $this->isAlertStatusCard($text)) {
            return $payload;
        }

        $enabled = (bool) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'map_button_enabled',
            true,
        );

        if (! $enabled) {
            if ($method === 'editMessageText') {
                $payload['reply_markup'] = ['inline_keyboard' => []];
            } else {
                unset($payload['reply_markup']);
            }

            return $payload;
        }

        $buttonText = trim((string) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'map_button_text',
            GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_TEXT,
        ));
        $buttonUrl = trim((string) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'map_button_url',
            GroupChannelBot::DEFAULT_ALERT_MAP_BUTTON_URL,
        ));

        if ($buttonText === '' || filter_var($buttonUrl, FILTER_VALIDATE_URL) === false) {
            if ($method === 'editMessageText') {
                $payload['reply_markup'] = ['inline_keyboard' => []];
            } else {
                unset($payload['reply_markup']);
            }

            return $payload;
        }

        $payload['reply_markup'] = [
            'inline_keyboard' => [[
                [
                    'text' => $buttonText,
                    'url' => $buttonUrl,
                ],
            ]],
        ];

        return $payload;
    }

    private function isAlertStatusCard(string $text): bool
    {
        return str_contains($text, '<b>🚨 ')
            || str_contains($text, '<b>🟢 ');
    }

    private function client(GroupChannelBot $bot): PendingRequest
    {
        return Http::baseUrl('https://api.telegram.org/bot'.$bot->bot_token)
            ->acceptJson()
            ->timeout(30);
    }

    private function createsMessage(string $method): bool
    {
        return in_array($method, [
            'sendMessage',
            'sendPhoto',
            'sendVideo',
            'sendDocument',
            'sendMediaGroup',
            'sendPoll',
            'copyMessage',
            'forwardMessage',
        ], true);
    }
}
