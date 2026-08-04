<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GroupedAlertTelegramService extends GroupChannelTelegramService
{
    public function request(GroupChannelBot $bot, string $method, array $payload = []): mixed
    {
        if ($method !== 'sendMessage' || ! is_string($payload['text'] ?? null)) {
            return parent::request($bot, $method, $payload);
        }

        $alert = $this->parseAlertMessage($payload['text']);

        if ($alert === null) {
            return parent::request($bot, $method, $payload);
        }

        $cacheKey = $this->cacheKey($bot, $alert);
        $cached = Cache::get($cacheKey, []);
        $regions = $this->unique([
            ...((array) ($cached['regions'] ?? [])),
            ...$alert['regions'],
        ]);
        $details = $this->unique([
            ...((array) ($cached['details'] ?? [])),
            ...$alert['details'],
        ]);
        $text = GroupChannelAlertMessageFormatter::render(
            $alert['kind'],
            $alert['threat_type'],
            $alert['time'],
            $regions,
            $details,
        );
        $messageId = is_numeric($cached['message_id'] ?? null)
            ? (int) $cached['message_id']
            : null;

        if ($messageId !== null
            && $regions === (array) ($cached['regions'] ?? [])
            && $details === (array) ($cached['details'] ?? [])) {
            return ['message_id' => $messageId];
        }

        if ($messageId !== null) {
            try {
                $result = parent::request($bot, 'editMessageText', [
                    'chat_id' => $payload['chat_id'] ?? $bot->chat_id,
                    'message_id' => $messageId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);
                $this->remember($cacheKey, $messageId, $regions, $details);

                return $result;
            } catch (Throwable) {
                // Telegram may refuse editing an old/deleted post. Send a new one instead.
            }
        }

        $result = parent::request($bot, 'sendMessage', [
            ...$payload,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
        $newMessageId = is_numeric($result['message_id'] ?? null)
            ? (int) $result['message_id']
            : null;

        if ($newMessageId !== null) {
            $this->remember($cacheKey, $newMessageId, $regions, $details);
        }

        return $result;
    }

    /**
     * @return array{kind: string, threat_type: string, time: string, regions: array<int, string>, details: array<int, string>}|null
     */
    private function parseAlertMessage(string $text): ?array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $kind = str_contains($text, '🕒 Початок:') ? 'start'
            : (str_contains($text, '🕒 Відбій:') ? 'end' : null);

        if ($kind === null) {
            return null;
        }

        $regions = [];
        $details = [];
        $threatType = '';
        $time = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '📍 ')) {
                $regions[] = trim(mb_substr($line, 2));
            } elseif (str_starts_with($line, '⚠️ ')) {
                $threatType = trim(mb_substr($line, 2));
            } elseif (str_starts_with($line, '🎯 ')) {
                $details[] = trim(mb_substr($line, 2));
            } elseif (preg_match('/🕒\s+(?:Початок|Відбій):\s*(\d{2}:\d{2})/u', $line, $matches)) {
                $time = $matches[1];
            }
        }

        if ($regions === [] || $time === '') {
            return null;
        }

        return [
            'kind' => $kind,
            'threat_type' => $threatType,
            'time' => $time,
            'regions' => $this->unique($regions),
            'details' => $this->unique($details),
        ];
    }

    /**
     * @param  array{kind: string, threat_type: string, time: string}  $alert
     */
    private function cacheKey(GroupChannelBot $bot, array $alert): string
    {
        $date = now('Europe/Kyiv')->format('Y-m-d');

        return 'skyguardian:grouped-alert:'.hash('sha256', implode('|', [
            (string) $bot->id,
            (string) $bot->chat_id,
            $alert['kind'],
            $alert['threat_type'],
            $date,
            $alert['time'],
        ]));
    }

    /**
     * @param  array<int, string>  $regions
     * @param  array<int, string>  $details
     */
    private function remember(string $key, int $messageId, array $regions, array $details): void
    {
        Cache::put($key, [
            'message_id' => $messageId,
            'regions' => $regions,
            'details' => $details,
        ], now()->addMinutes(2));
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function unique(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            $value = trim($value);

            if ($value !== '') {
                $unique[mb_strtolower($value)] = $value;
            }
        }

        return array_values($unique);
    }
}
