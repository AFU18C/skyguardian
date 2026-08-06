<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class SourcePollingSettings
{
    public const DEFAULT_INTERVAL_MINUTES = 1;

    public const MIN_INTERVAL_MINUTES = 1;

    public const MAX_INTERVAL_MINUTES = 1440;

    /**
     * @return array{enabled: bool, interval_minutes: int}
     */
    public function get(string $type): array
    {
        $this->ensureType($type);

        $stored = SiteSetting::query()
            ->where('key', $this->settingKey($type))
            ->first()?->value;
        $stored = is_array($stored) ? $stored : [];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'interval_minutes' => $this->normalizeInterval(
                $stored['interval_minutes'] ?? self::DEFAULT_INTERVAL_MINUTES,
            ),
        ];
    }

    /**
     * @return array{enabled: bool, interval_minutes: int}
     */
    public function update(string $type, bool $enabled, int $intervalMinutes): array
    {
        $this->ensureType($type);

        $settings = [
            'enabled' => $enabled,
            'interval_minutes' => $this->normalizeInterval($intervalMinutes),
        ];

        SiteSetting::query()->updateOrCreate(
            ['key' => $this->settingKey($type)],
            ['value' => $settings],
        );

        Cache::forget($this->lastRunCacheKey($type));

        return $settings;
    }

    public function shouldRun(string $type, ?CarbonInterface $now = null): bool
    {
        $settings = $this->get($type);

        if (! $settings['enabled']) {
            return false;
        }

        $lastRun = Cache::get($this->lastRunCacheKey($type));

        if (! is_string($lastRun) || trim($lastRun) === '') {
            return true;
        }

        try {
            $lastRunAt = CarbonImmutable::parse($lastRun);
        } catch (Throwable) {
            return true;
        }

        $now ??= now();

        return $lastRunAt
            ->addMinutes($settings['interval_minutes'])
            ->lessThanOrEqualTo($now);
    }

    public function markRun(string $type, ?CarbonInterface $at = null): void
    {
        $this->ensureType($type);
        $at ??= now();

        Cache::forever($this->lastRunCacheKey($type), $at->toIso8601String());
    }

    private function normalizeInterval(mixed $value): int
    {
        return max(
            self::MIN_INTERVAL_MINUTES,
            min(self::MAX_INTERVAL_MINUTES, (int) $value),
        );
    }

    private function settingKey(string $type): string
    {
        return 'source_polling_'.$type;
    }

    private function lastRunCacheKey(string $type): string
    {
        return 'skyguardian:source-polling:last-run:'.$type;
    }

    private function ensureType(string $type): void
    {
        if (! in_array($type, [Source::TYPE_NEWS, Source::TYPE_AIR_ALERT], true)) {
            throw new InvalidArgumentException('Недопустимый тип источника.');
        }
    }
}
