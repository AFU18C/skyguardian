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
    public const DEFAULT_INTERVAL_VALUE = 1;

    public const DEFAULT_INTERVAL_UNIT = 'minutes';

    public const MIN_INTERVAL_SECONDS = 1;

    public const MAX_INTERVAL_SECONDS = 86400;

    /**
     * @return array{enabled: bool, interval_value: int, interval_unit: string, interval_seconds: int}
     */
    public function get(string $type): array
    {
        $this->ensureType($type);

        $stored = SiteSetting::query()
            ->where('key', $this->settingKey($type))
            ->first()?->value;
        $stored = is_array($stored) ? $stored : [];

        if (isset($stored['interval_seconds'])) {
            $seconds = $this->normalizeSeconds($stored['interval_seconds']);
            $unit = $this->normalizeUnit($stored['interval_unit'] ?? self::DEFAULT_INTERVAL_UNIT);
            $value = max(1, (int) ($stored['interval_value'] ?? $this->valueFromSeconds($seconds, $unit)));
        } else {
            // Backward compatibility with settings saved before second-level intervals were introduced.
            $legacyMinutes = max(1, min(1440, (int) ($stored['interval_minutes'] ?? self::DEFAULT_INTERVAL_VALUE)));
            $unit = 'minutes';
            $value = $legacyMinutes;
            $seconds = $legacyMinutes * 60;
        }

        return [
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'interval_value' => $value,
            'interval_unit' => $unit,
            'interval_seconds' => $seconds,
        ];
    }

    /**
     * @return array{enabled: bool, interval_value: int, interval_unit: string, interval_seconds: int}
     */
    public function update(
        string $type,
        bool $enabled,
        int $intervalValue,
        string $intervalUnit = self::DEFAULT_INTERVAL_UNIT,
    ): array {
        $this->ensureType($type);

        $unit = $this->normalizeUnit($intervalUnit);
        $seconds = $this->normalizeSeconds($this->toSeconds($intervalValue, $unit));
        $value = max(1, $intervalValue);

        $settings = [
            'enabled' => $enabled,
            'interval_value' => $value,
            'interval_unit' => $unit,
            'interval_seconds' => $seconds,
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
            ->addSeconds($settings['interval_seconds'])
            ->lessThanOrEqualTo($now);
    }

    public function markRun(string $type, ?CarbonInterface $at = null): void
    {
        $this->ensureType($type);
        $at ??= now();

        Cache::forever($this->lastRunCacheKey($type), $at->toIso8601String());
    }

    public function intervalSeconds(int $value, string $unit): int
    {
        return $this->normalizeSeconds($this->toSeconds($value, $this->normalizeUnit($unit)));
    }

    private function toSeconds(int $value, string $unit): int
    {
        $value = max(1, $value);

        return match ($unit) {
            'seconds' => $value,
            'hours' => $value * 3600,
            default => $value * 60,
        };
    }

    private function valueFromSeconds(int $seconds, string $unit): int
    {
        return match ($unit) {
            'seconds' => $seconds,
            'hours' => max(1, intdiv($seconds, 3600)),
            default => max(1, intdiv($seconds, 60)),
        };
    }

    private function normalizeSeconds(mixed $value): int
    {
        return max(
            self::MIN_INTERVAL_SECONDS,
            min(self::MAX_INTERVAL_SECONDS, (int) $value),
        );
    }

    private function normalizeUnit(mixed $value): string
    {
        $unit = (string) $value;

        return in_array($unit, ['seconds', 'minutes', 'hours'], true)
            ? $unit
            : self::DEFAULT_INTERVAL_UNIT;
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
