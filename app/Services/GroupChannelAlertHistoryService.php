<?php

namespace App\Services;

use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelAlertState;
use App\Models\GroupChannelBot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GroupChannelAlertHistoryService
{
    public function __construct(private readonly DirectGroupChannelTelegramService $telegram) {}

    public function handleStart(GroupChannelBot $bot, array $message): bool
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = $chat['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (($chat['type'] ?? null) !== 'private'
            || ! is_numeric($chatId)
            || ! preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s+(ah_[A-Za-z0-9_]+)$/', $text, $matches)) {
            return false;
        }

        $history = $this->parseStartPayload($matches[1]);
        if ($history === null || $history['bot_id'] !== (int) $bot->id) {
            $this->telegram->request($bot, 'sendMessage', [
                'chat_id' => (int) $chatId,
                'text' => 'Посилання на історію тривоги недійсне.',
            ]);

            return true;
        }

        $this->sendHistory(
            $bot,
            (int) $chatId,
            $history['scope_region_uid'],
            $history['alert_type'],
            $history['cycle_started_at'],
        );

        return true;
    }

    public function handleRefreshCallback(GroupChannelBot $bot, array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, 'sg_ahr:')) {
            return false;
        }

        $callbackId = $callback['id'] ?? null;
        $chat = is_array(data_get($callback, 'message.chat'))
            ? data_get($callback, 'message.chat')
            : [];
        $chatId = $chat['id'] ?? null;
        $fromId = data_get($callback, 'from.id');
        $history = $this->parseRefreshPayload($data);

        if (($chat['type'] ?? null) !== 'private'
            || ! is_numeric($chatId)
            || (string) $fromId !== (string) $chatId
            || $history === null
            || $history['bot_id'] !== (int) $bot->id) {
            $this->answerCallback($bot, $callbackId, 'Не вдалося оновити історію.', true);

            return true;
        }

        $this->sendHistory(
            $bot,
            (int) $chatId,
            $history['scope_region_uid'],
            $history['alert_type'],
            $history['cycle_started_at'],
        );
        $this->answerCallback($bot, $callbackId, 'Актуальну історію надіслано нижче.');

        return true;
    }

    private function sendHistory(
        GroupChannelBot $bot,
        int $chatId,
        string $scopeRegionUid,
        string $alertType,
        CarbonImmutable $cycleStartedAt,
    ): void {
        $snapshot = $this->snapshot($bot, $scopeRegionUid, $alertType, $cycleStartedAt);

        if ($snapshot === null) {
            $this->telegram->request($bot, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => "📊 ІСТОРІЯ ТРИВОГИ\n\nДані для цієї тривоги поки недоступні.",
                'reply_markup' => $this->replyMarkup(
                    $bot,
                    $scopeRegionUid,
                    $alertType,
                    $cycleStartedAt,
                ),
            ]);

            return;
        }

        $pages = $this->renderPages($snapshot);
        $lastIndex = count($pages) - 1;

        foreach ($pages as $index => $page) {
            $payload = [
                'chat_id' => $chatId,
                'text' => $page,
                'disable_web_page_preview' => true,
            ];

            if ($index === $lastIndex) {
                $payload['reply_markup'] = $this->replyMarkup(
                    $bot,
                    $scopeRegionUid,
                    $alertType,
                    $cycleStartedAt,
                );
            }

            $this->telegram->request($bot, 'sendMessage', $payload);
        }
    }

    /**
     * @return array{
     *     oblast: string,
     *     alert_type: string,
     *     started_at: CarbonImmutable,
     *     ended_at: ?CarbonImmutable,
     *     updated_at: CarbonImmutable,
     *     is_active: bool,
     *     active_count: int,
     *     territory_count: int,
     *     peak_count: int,
     *     episodes: Collection<int, array{
     *         region_uid: string,
     *         label: string,
     *         started_at: CarbonImmutable,
     *         ended_at: ?CarbonImmutable
     *     }>
     * }|null
     */
    private function snapshot(
        GroupChannelBot $bot,
        string $scopeRegionUid,
        string $alertType,
        CarbonImmutable $cycleStartedAt,
    ): ?array {
        $oblast = GroupChannelBot::ALERT_REGIONS[$scopeRegionUid] ?? null;
        if ($oblast === null || ! isset(GroupChannelBot::ALERT_TYPES[$alertType])) {
            return null;
        }

        $from = $cycleStartedAt->subSecond();
        $endedEpisodes = GroupChannelAlertEvent::query()
            ->where('group_channel_bot_id', $bot->id)
            ->where('kind', GroupChannelAlertEvent::KIND_END)
            ->where('scope_region_uid', $scopeRegionUid)
            ->where('alert_type', $alertType)
            ->whereNotNull('started_at')
            ->where('started_at', '>=', $from)
            ->orderBy('started_at')
            ->orderBy('event_at')
            ->orderBy('id')
            ->get()
            ->map(fn (GroupChannelAlertEvent $event): array => [
                'region_uid' => (string) $event->region_uid,
                'label' => $this->territoryLabel($event->region_name, $oblast),
                'started_at' => $event->started_at->toImmutable(),
                'ended_at' => $event->event_at->toImmutable(),
            ]);

        $activeEpisodes = GroupChannelAlertState::query()
            ->where('group_channel_bot_id', $bot->id)
            ->where('scope_region_uid', $scopeRegionUid)
            ->where('alert_type', $alertType)
            ->whereNotNull('started_at')
            ->where('started_at', '>=', $from)
            ->orderBy('started_at')
            ->orderBy('region_name')
            ->get()
            ->map(fn (GroupChannelAlertState $state): array => [
                'region_uid' => (string) $state->region_uid,
                'label' => $this->territoryLabel($state->region_name, $oblast),
                'started_at' => $state->started_at->toImmutable(),
                'ended_at' => null,
            ]);

        $candidates = $endedEpisodes
            ->concat($activeEpisodes)
            ->sortBy(fn (array $episode): string => sprintf(
                '%010d|%s|%s',
                $episode['started_at']->getTimestamp(),
                $episode['region_uid'],
                $episode['ended_at']?->getTimestamp() ?? PHP_INT_MAX,
            ))
            ->values();

        $episodes = $this->continuousCycleEpisodes($candidates, $cycleStartedAt);
        if ($episodes->isEmpty()) {
            return null;
        }

        $active = $episodes->filter(fn (array $episode): bool => $episode['ended_at'] === null);
        $isActive = $active->isNotEmpty();
        $endedAt = $isActive
            ? null
            : $episodes
                ->pluck('ended_at')
                ->filter()
                ->sortByDesc(fn (CarbonImmutable $date): int => $date->getTimestamp())
                ->first()?->toImmutable();

        return [
            'oblast' => $oblast,
            'alert_type' => $alertType,
            'started_at' => $episodes
                ->pluck('started_at')
                ->sortBy(fn (CarbonImmutable $date): int => $date->getTimestamp())
                ->first()?->toImmutable() ?? $cycleStartedAt,
            'ended_at' => $endedAt,
            'updated_at' => CarbonImmutable::now('UTC'),
            'is_active' => $isActive,
            'active_count' => $active->pluck('region_uid')->unique()->count(),
            'territory_count' => $episodes->pluck('region_uid')->unique()->count(),
            'peak_count' => $this->peakTerritoryCount($episodes),
            'episodes' => $episodes,
        ];
    }

    /**
     * @param  Collection<int, array{
     *     region_uid: string,
     *     label: string,
     *     started_at: CarbonImmutable,
     *     ended_at: ?CarbonImmutable
     * }>  $candidates
     * @return Collection<int, array{
     *     region_uid: string,
     *     label: string,
     *     started_at: CarbonImmutable,
     *     ended_at: ?CarbonImmutable
     * }>
     */
    private function continuousCycleEpisodes(Collection $candidates, CarbonImmutable $cycleStartedAt): Collection
    {
        $episodes = collect();
        $coverageEnd = null;
        $coverageOpen = false;
        $earliestAllowed = $cycleStartedAt->subSecond();
        $latestFirstStart = $cycleStartedAt->addSecond();

        foreach ($candidates as $episode) {
            $startedAt = $episode['started_at'];

            if ($episodes->isEmpty()) {
                if ($startedAt->lessThan($earliestAllowed)) {
                    continue;
                }

                if ($startedAt->greaterThan($latestFirstStart)) {
                    break;
                }
            } elseif (! $coverageOpen
                && $coverageEnd instanceof CarbonImmutable
                && $startedAt->greaterThan($coverageEnd)) {
                // There was a moment with zero active territories. Anything after
                // that belongs to a later alert cycle and must not leak into this one.
                break;
            }

            $episodes->push($episode);

            if ($episode['ended_at'] === null) {
                $coverageOpen = true;

                continue;
            }

            if (! $coverageOpen
                && (! $coverageEnd instanceof CarbonImmutable
                    || $episode['ended_at']->greaterThan($coverageEnd))) {
                $coverageEnd = $episode['ended_at'];
            }
        }

        return $episodes->values();
    }

    /** @param Collection<int, array<string, mixed>> $episodes */
    private function peakTerritoryCount(Collection $episodes): int
    {
        $points = [];

        foreach ($episodes as $episode) {
            $points[] = [
                'at' => $episode['started_at']->getTimestamp(),
                'delta' => 1,
                'region_uid' => $episode['region_uid'],
            ];

            if ($episode['ended_at'] instanceof CarbonImmutable) {
                $points[] = [
                    'at' => $episode['ended_at']->getTimestamp(),
                    'delta' => -1,
                    'region_uid' => $episode['region_uid'],
                ];
            }
        }

        usort($points, static fn (array $a, array $b): int => [$a['at'], -$a['delta']]
            <=> [$b['at'], -$b['delta']]);

        $counts = [];
        $peak = 0;

        foreach ($points as $point) {
            $uid = $point['region_uid'];
            $counts[$uid] = max(0, ($counts[$uid] ?? 0) + $point['delta']);
            $active = count(array_filter($counts, static fn (int $count): bool => $count > 0));
            $peak = max($peak, $active);
        }

        return $peak;
    }

    /** @param array<string, mixed> $snapshot */
    private function renderPages(array $snapshot): array
    {
        /** @var CarbonImmutable $startedAt */
        $startedAt = $snapshot['started_at'];
        /** @var CarbonImmutable $updatedAt */
        $updatedAt = $snapshot['updated_at'];
        /** @var CarbonImmutable|null $endedAt */
        $endedAt = $snapshot['ended_at'];
        /** @var Collection<int, array<string, mixed>> $episodes */
        $episodes = $snapshot['episodes'];
        $headline = mb_strtoupper(GroupChannelBot::ALERT_TYPES[$snapshot['alert_type']] ?? $snapshot['alert_type']);
        $summary = [
            "📊 ІСТОРІЯ: {$headline}",
            '',
            '📍 '.$snapshot['oblast'],
            '',
            $snapshot['is_active'] ? '🔴 СТАТУС: ТРИВАЄ' : '🟢 СТАТУС: ЗАВЕРШЕНА',
            '🔴 Початок: '.$this->formatHistoryTime($startedAt, $startedAt),
        ];

        if ($snapshot['is_active']) {
            $summary[] = '⏱ Триває вже: '.$this->formatDuration($startedAt, $updatedAt);
            $summary[] = '🚨 Зараз активні: '.$snapshot['active_count'].' '.$this->territoryWord($snapshot['active_count']);
            $summary[] = '📍 Всього було охоплено: '.$snapshot['territory_count'].' '.$this->territoryWord($snapshot['territory_count']);
        } else {
            $summary[] = '🟢 Повний відбій: '.$this->formatHistoryTime($endedAt, $startedAt);
            $summary[] = '⏱ Загальна тривалість: '.$this->formatDuration($startedAt, $endedAt ?? $updatedAt);
            $summary[] = '📍 Територій під час тривоги: '.$snapshot['territory_count'].' '.$this->territoryWord($snapshot['territory_count']);
        }

        $summary[] = '📈 Максимально одночасно: '.$snapshot['peak_count'].' '.$this->territoryWord($snapshot['peak_count']);

        $timeline = collect();
        foreach ($episodes as $episode) {
            $timeline->push([
                'at' => $episode['started_at'],
                'kind' => 'start',
                'label' => $episode['label'],
            ]);

            if ($episode['ended_at'] instanceof CarbonImmutable) {
                $timeline->push([
                    'at' => $episode['ended_at'],
                    'kind' => 'end',
                    'label' => $episode['label'],
                ]);
            }
        }

        $timelineLines = $timeline
            ->sortBy(fn (array $item): string => sprintf(
                '%010d|%d|%s',
                $item['at']->getTimestamp(),
                $item['kind'] === 'start' ? 0 : 1,
                $item['label'],
            ))
            ->map(fn (array $item): string => $this->formatHistoryTime($item['at'], $startedAt)
                .' '.($item['kind'] === 'start' ? '🔴 ' : '🟢 ')
                .$item['label']
                .($item['kind'] === 'end' ? ' — відбій' : ''))
            ->values();

        $durationLines = $episodes
            ->sortBy(fn (array $episode): string => sprintf(
                '%s|%010d',
                $episode['label'],
                $episode['started_at']->getTimestamp(),
            ))
            ->map(function (array $episode) use ($startedAt, $updatedAt): string {
                $end = $episode['ended_at'] instanceof CarbonImmutable
                    ? $episode['ended_at']
                    : $updatedAt;
                $endLabel = $episode['ended_at'] instanceof CarbonImmutable
                    ? $this->formatHistoryTime($episode['ended_at'], $startedAt)
                    : 'зараз';

                return '› '.$episode['label'].' — '
                    .$this->formatHistoryTime($episode['started_at'], $startedAt)
                    .' → '.$endLabel
                    .' · '.$this->formatDuration($episode['started_at'], $end);
            })
            ->values();

        $lines = $summary;
        $lines[] = '';
        $lines[] = '🚨 ХРОНОЛОГІЯ';
        foreach ($timelineLines as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '⏱ ТРИВАЛІСТЬ ПО ТЕРИТОРІЯХ';
        foreach ($durationLines as $line) {
            $lines[] = $line;
        }

        if (! $snapshot['is_active'] && $endedAt instanceof CarbonImmutable) {
            $lines[] = '';
            $lines[] = '✅ Повний відбій о '.$this->formatHistoryTime($endedAt, $startedAt);
        }

        $lines[] = '';
        $lines[] = '🔄 Оновлено: '.$updatedAt->timezone('Europe/Kyiv')->format('H:i');

        return $this->paginateLines($lines);
    }

    /** @param array<int, string> $lines */
    private function paginateLines(array $lines): array
    {
        $pages = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current."\n".$line;

            if ($current !== '' && $this->telegramUtf16Length($candidate) > 3900) {
                $pages[] = trim($current);
                $current = $line;

                continue;
            }

            $current = $candidate;
        }

        if (trim($current) !== '') {
            $pages[] = trim($current);
        }

        return $pages !== [] ? $pages : ['📊 ІСТОРІЯ ТРИВОГИ'];
    }

    /** @return array{inline_keyboard: array<int, array<int, array<string, string>>>} */
    private function replyMarkup(
        GroupChannelBot $bot,
        string $scopeRegionUid,
        string $alertType,
        CarbonImmutable $cycleStartedAt,
    ): array {
        $typeCode = $this->alertTypeCode($alertType);
        $keyboard = [];

        if ($typeCode !== null) {
            $callbackData = implode(':', [
                'sg_ahr',
                $bot->id,
                $scopeRegionUid,
                $typeCode,
                $cycleStartedAt->getTimestamp(),
            ]);

            if (strlen($callbackData) <= 64) {
                $keyboard[] = [[
                    'text' => '🔄 Оновити історію',
                    'callback_data' => $callbackData,
                ]];
            }
        }

        if ((bool) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            'map_button_enabled',
            true,
        )) {
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

            if ($buttonText !== '' && filter_var($buttonUrl, FILTER_VALIDATE_URL) !== false) {
                $keyboard[] = [[
                    'text' => $buttonText,
                    'url' => $buttonUrl,
                ]];
            }
        }

        return ['inline_keyboard' => $keyboard];
    }

    private function answerCallback(
        GroupChannelBot $bot,
        mixed $callbackId,
        string $text,
        bool $showAlert = false,
    ): void {
        if (! is_string($callbackId) || $callbackId === '') {
            return;
        }

        $payload = [
            'callback_query_id' => $callbackId,
            'text' => $text,
        ];

        if ($showAlert) {
            $payload['show_alert'] = true;
        }

        $this->telegram->request($bot, 'answerCallbackQuery', $payload);
    }

    /**
     * @return array{
     *     bot_id: int,
     *     scope_region_uid: string,
     *     alert_type: string,
     *     cycle_started_at: CarbonImmutable
     * }|null
     */
    private function parseStartPayload(string $payload): ?array
    {
        $parts = explode('_', $payload);
        if (count($parts) !== 6 || $parts[0] !== 'ah') {
            return null;
        }

        [, $botId, $scopeRegionUid, $typeCode, $cycleTimestamp, $untilTimestamp] = $parts;
        $alertType = $this->alertTypeFromCode($typeCode);

        if (! ctype_digit($botId)
            || ! ctype_digit($scopeRegionUid)
            || ! array_key_exists($scopeRegionUid, GroupChannelBot::ALERT_REGIONS)
            || $alertType === null
            || ! ctype_digit($cycleTimestamp)
            || ! ctype_digit($untilTimestamp)) {
            return null;
        }

        $cycleStartedAt = CarbonImmutable::createFromTimestampUTC((int) $cycleTimestamp);
        $historyUntil = CarbonImmutable::createFromTimestampUTC((int) $untilTimestamp);

        if ($historyUntil->lessThan($cycleStartedAt)) {
            return null;
        }

        return [
            'bot_id' => (int) $botId,
            'scope_region_uid' => $scopeRegionUid,
            'alert_type' => $alertType,
            'cycle_started_at' => $cycleStartedAt,
        ];
    }

    /**
     * @return array{
     *     bot_id: int,
     *     scope_region_uid: string,
     *     alert_type: string,
     *     cycle_started_at: CarbonImmutable
     * }|null
     */
    private function parseRefreshPayload(string $payload): ?array
    {
        $parts = explode(':', $payload);
        if (count($parts) !== 5 || $parts[0] !== 'sg_ahr') {
            return null;
        }

        [, $botId, $scopeRegionUid, $typeCode, $cycleTimestamp] = $parts;
        $alertType = $this->alertTypeFromCode($typeCode);

        if (! ctype_digit($botId)
            || ! ctype_digit($scopeRegionUid)
            || ! array_key_exists($scopeRegionUid, GroupChannelBot::ALERT_REGIONS)
            || $alertType === null
            || ! ctype_digit($cycleTimestamp)) {
            return null;
        }

        return [
            'bot_id' => (int) $botId,
            'scope_region_uid' => $scopeRegionUid,
            'alert_type' => $alertType,
            'cycle_started_at' => CarbonImmutable::createFromTimestampUTC((int) $cycleTimestamp),
        ];
    }

    private function alertTypeCode(string $alertType): ?string
    {
        return match ($alertType) {
            'air_raid' => 'a',
            'artillery_shelling' => 's',
            'urban_fights' => 'u',
            'chemical' => 'c',
            'nuclear' => 'n',
            default => null,
        };
    }

    private function alertTypeFromCode(string $code): ?string
    {
        return match ($code) {
            'a' => 'air_raid',
            's' => 'artillery_shelling',
            'u' => 'urban_fights',
            'c' => 'chemical',
            'n' => 'nuclear',
            default => null,
        };
    }

    private function territoryLabel(string $regionName, string $oblast): string
    {
        $regionName = trim($regionName);

        if ($regionName === '' || $regionName === $oblast) {
            return $regionName !== '' ? $regionName : 'Невідома локація';
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(' — ', $regionName)),
            fn (string $part): bool => $part !== '',
        ));

        return $parts !== [] ? (string) end($parts) : $regionName;
    }

    private function territoryWord(int $count): string
    {
        $lastTwo = $count % 100;
        $last = $count % 10;

        if ($lastTwo >= 11 && $lastTwo <= 14) {
            return 'територій';
        }

        return match ($last) {
            1 => 'територія',
            2, 3, 4 => 'території',
            default => 'територій',
        };
    }

    private function formatHistoryTime(?CarbonImmutable $date, CarbonImmutable $cycleStartedAt): string
    {
        if (! $date) {
            return 'невідомо';
        }

        $local = $date->timezone('Europe/Kyiv');
        $cycleLocal = $cycleStartedAt->timezone('Europe/Kyiv');

        return $local->format('Y-m-d') === $cycleLocal->format('Y-m-d')
            ? $local->format('H:i')
            : $local->format('d.m H:i');
    }

    private function formatDuration(CarbonImmutable $startedAt, CarbonImmutable $endedAt): string
    {
        $totalMinutes = max(0, (int) floor($startedAt->diffInMinutes($endedAt)));
        $days = intdiv($totalMinutes, 1440);
        $hours = intdiv($totalMinutes % 1440, 60);
        $minutes = $totalMinutes % 60;
        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' д';
        }

        if ($hours > 0) {
            $parts[] = $hours.' год';
        }

        if ($minutes > 0 || $parts === []) {
            $parts[] = $minutes.' хв';
        }

        return implode(' ', $parts);
    }

    private function telegramUtf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
