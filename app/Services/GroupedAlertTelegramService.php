<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use Carbon\CarbonImmutable;
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

        $results = [];

        foreach ($this->splitByOblast($alert) as $oblastAlert) {
            $results[] = $oblastAlert['kind'] === 'end'
                ? $this->deliverEndAlert($bot, $payload, $oblastAlert)
                : $this->deliverStartAlert($bot, $payload, $oblastAlert);
        }

        return $results !== [] ? end($results) : null;
    }

    /**
     * @param  array{kind: string, threat_type: string, time: string, oblast: string, regions: array<int, string>, details: array<int, string>}  $alert
     */
    private function deliverStartAlert(GroupChannelBot $bot, array $payload, array $alert): mixed
    {
        $cacheKey = $this->cacheKey($bot, $alert);
        $cached = Cache::get($cacheKey, []);
        $regions = $this->unique([
            ...((array) ($cached['regions'] ?? [])),
            ...$alert['regions'],
        ]);
        $allRegions = $this->unique([
            ...((array) ($cached['all_regions'] ?? $cached['regions'] ?? [])),
            ...$alert['regions'],
        ]);
        $details = $this->unique([
            ...((array) ($cached['details'] ?? [])),
            ...$alert['details'],
        ]);
        $time = is_string($cached['time'] ?? null)
            ? $cached['time']
            : $alert['time'];
        $startedAt = is_string($cached['started_at'] ?? null)
            ? $cached['started_at']
            : $this->startedAt($time)->toIso8601String();
        $text = GroupChannelAlertMessageFormatter::render(
            $alert['kind'],
            $alert['threat_type'],
            $time,
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
                $this->remember(
                    $cacheKey,
                    $messageId,
                    $time,
                    $startedAt,
                    $regions,
                    $allRegions,
                    $details,
                );

                return $result;
            } catch (Throwable) {
                // Telegram may refuse editing an old or deleted post. Send a new one instead.
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
            $this->remember(
                $cacheKey,
                $newMessageId,
                $time,
                $startedAt,
                $regions,
                $allRegions,
                $details,
            );
        }

        return $result;
    }

    /**
     * @param  array{kind: string, threat_type: string, time: string, oblast: string, regions: array<int, string>, details: array<int, string>}  $alert
     */
    private function deliverEndAlert(GroupChannelBot $bot, array $payload, array $alert): mixed
    {
        [$cacheKey, $cached, $threatType] = $this->findCachedAlert($bot, $alert);
        $messageId = is_numeric($cached['message_id'] ?? null)
            ? (int) $cached['message_id']
            : null;

        if ($cacheKey === null || $messageId === null) {
            return parent::request($bot, 'sendMessage', [
                ...$payload,
                'text' => GroupChannelAlertMessageFormatter::render(
                    'end',
                    $threatType,
                    $alert['time'],
                    $alert['regions'],
                ),
                'parse_mode' => 'HTML',
            ]);
        }

        $activeRegions = $this->remainingRegions(
            (array) ($cached['regions'] ?? []),
            $alert['regions'],
        );
        $allRegions = $this->unique((array) (
            $cached['all_regions'] ?? $cached['regions'] ?? $alert['regions']
        ));
        $startTime = is_string($cached['time'] ?? null)
            ? $cached['time']
            : null;
        $startedAt = is_string($cached['started_at'] ?? null)
            ? $cached['started_at']
            : ($startTime !== null ? $this->startedAt($startTime)->toIso8601String() : null);

        if ($activeRegions !== []) {
            $text = GroupChannelAlertMessageFormatter::render(
                'start',
                $threatType,
                $startTime ?? $alert['time'],
                $activeRegions,
                (array) ($cached['details'] ?? []),
            );

            try {
                $result = parent::request($bot, 'editMessageText', [
                    'chat_id' => $payload['chat_id'] ?? $bot->chat_id,
                    'message_id' => $messageId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);
                $this->remember(
                    $cacheKey,
                    $messageId,
                    $startTime ?? $alert['time'],
                    $startedAt ?? $this->startedAt($alert['time'])->toIso8601String(),
                    $activeRegions,
                    $allRegions,
                    (array) ($cached['details'] ?? []),
                );

                return $result;
            } catch (Throwable) {
                $result = parent::request($bot, 'sendMessage', [
                    ...$payload,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);
                $newMessageId = is_numeric($result['message_id'] ?? null)
                    ? (int) $result['message_id']
                    : null;

                if ($newMessageId !== null) {
                    $this->remember(
                        $cacheKey,
                        $newMessageId,
                        $startTime ?? $alert['time'],
                        $startedAt ?? $this->startedAt($alert['time'])->toIso8601String(),
                        $activeRegions,
                        $allRegions,
                        (array) ($cached['details'] ?? []),
                    );
                }

                return $result;
            }
        }

        $durationMinutes = $startedAt !== null
            ? $this->durationMinutes($startedAt, $alert['time'])
            : null;
        $text = GroupChannelAlertMessageFormatter::render(
            'end',
            $threatType,
            $alert['time'],
            $allRegions,
            [],
            $startTime,
            $durationMinutes,
        );

        try {
            $result = parent::request($bot, 'editMessageText', [
                'chat_id' => $payload['chat_id'] ?? $bot->chat_id,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
            Cache::forget($cacheKey);

            return $result;
        } catch (Throwable) {
            Cache::forget($cacheKey);

            return parent::request($bot, 'sendMessage', [
                ...$payload,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        }
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
     * @param  array{kind: string, threat_type: string, time: string, regions: array<int, string>, details: array<int, string>}  $alert
     * @return array<int, array{kind: string, threat_type: string, time: string, oblast: string, regions: array<int, string>, details: array<int, string>}>
     */
    private function splitByOblast(array $alert): array
    {
        $groups = [];

        foreach ($alert['regions'] as $region) {
            $oblast = trim(explode(' — ', $region, 2)[0] ?? '');

            if ($oblast === '') {
                continue;
            }

            $key = mb_strtolower($oblast);
            $groups[$key] ??= [
                ...$alert,
                'oblast' => $oblast,
                'regions' => [],
            ];
            $groups[$key]['regions'][] = $region;
        }

        return array_values(array_map(function (array $group): array {
            $group['regions'] = $this->unique($group['regions']);

            return $group;
        }, $groups));
    }

    /**
     * @param  array{threat_type: string, oblast: string, regions: array<int, string>}  $alert
     * @return array{0: string|null, 1: array<string, mixed>, 2: string}
     */
    private function findCachedAlert(GroupChannelBot $bot, array $alert): array
    {
        $types = $alert['threat_type'] !== ''
            ? [$alert['threat_type']]
            : ['', ...array_values(GroupChannelBot::ALERT_TYPES)];
        $types = array_values(array_unique($types));
        $bestKey = null;
        $bestCached = [];
        $bestType = $alert['threat_type'];
        $bestScore = -1;
        $bestUpdatedAt = '';

        foreach ($types as $threatType) {
            $candidate = [...$alert, 'threat_type' => $threatType];
            $key = $this->cacheKey($bot, $candidate);
            $cached = Cache::get($key, []);

            if (! is_numeric($cached['message_id'] ?? null)) {
                continue;
            }

            $score = count((array) ($cached['regions'] ?? []))
                - count($this->remainingRegions((array) ($cached['regions'] ?? []), $alert['regions']));
            $updatedAt = is_string($cached['updated_at'] ?? null)
                ? $cached['updated_at']
                : '';

            if ($score > $bestScore || ($score === $bestScore && $updatedAt > $bestUpdatedAt)) {
                $bestKey = $key;
                $bestCached = $cached;
                $bestType = $threatType;
                $bestScore = $score;
                $bestUpdatedAt = $updatedAt;
            }
        }

        return [$bestKey, $bestCached, $bestType];
    }

    /**
     * @param  array{threat_type: string, oblast: string}  $alert
     */
    private function cacheKey(GroupChannelBot $bot, array $alert): string
    {
        return 'skyguardian:grouped-alert:'.hash('sha256', implode('|', [
            (string) $bot->id,
            (string) $bot->chat_id,
            mb_strtolower($alert['threat_type']),
            mb_strtolower($alert['oblast']),
        ]));
    }

    /**
     * @param  array<int, string>  $regions
     * @param  array<int, string>  $allRegions
     * @param  array<int, string>  $details
     */
    private function remember(
        string $key,
        int $messageId,
        string $time,
        string $startedAt,
        array $regions,
        array $allRegions,
        array $details,
    ): void {
        Cache::put($key, [
            'message_id' => $messageId,
            'time' => $time,
            'started_at' => $startedAt,
            'regions' => $regions,
            'all_regions' => $allRegions,
            'details' => $details,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDays(7));
    }

    /**
     * @param  array<int, string>  $activeRegions
     * @param  array<int, string>  $clearedRegions
     * @return array<int, string>
     */
    private function remainingRegions(array $activeRegions, array $clearedRegions): array
    {
        return array_values(array_filter(
            $this->unique($activeRegions),
            fn (string $active): bool => ! collect($clearedRegions)->contains(
                fn (string $cleared): bool => $this->clearsRegion($active, $cleared),
            ),
        ));
    }

    private function clearsRegion(string $active, string $cleared): bool
    {
        $active = mb_strtolower(trim($active));
        $cleared = mb_strtolower(trim($cleared));

        if ($active === $cleared) {
            return true;
        }

        if (! str_contains($cleared, ' — ')) {
            return str_starts_with($active, $cleared.' — ');
        }

        return str_starts_with($active, $cleared.' — ');
    }

    private function startedAt(string $time): CarbonImmutable
    {
        $now = CarbonImmutable::now('Europe/Kyiv');
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $startedAt = $now->setTime($hour, $minute);

        return $startedAt->greaterThan($now->addMinutes(5))
            ? $startedAt->subDay()
            : $startedAt;
    }

    private function durationMinutes(string $startedAt, string $endTime): int
    {
        $start = CarbonImmutable::parse($startedAt)->timezone('Europe/Kyiv');
        [$hour, $minute] = array_map('intval', explode(':', $endTime));
        $end = CarbonImmutable::now('Europe/Kyiv')->setTime($hour, $minute);

        while ($end->lessThan($start)) {
            $end = $end->addDay();
        }

        return max(0, (int) $start->diffInMinutes($end));
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
