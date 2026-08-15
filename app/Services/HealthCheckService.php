<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCheckService
{
    public const SCHEDULER_HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    /**
     * @return array{healthy: bool, checked_at: string, checks: array<string, array{status: string, detail?: string}>}
     */
    public function snapshot(): array
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'scheduler' => $this->scheduler(),
            'telethon' => $this->socket(
                (string) config('skyguardian.telethon.host'),
                (int) config('skyguardian.telethon.port'),
            ),
            'group_channel_telethon' => $this->socket(
                (string) config('skyguardian.group_channel_telethon.host'),
                (int) config('skyguardian.group_channel_telethon.port'),
            ),
            'disk' => $this->disk(),
            'backup' => $this->backup(),
        ];

        return [
            'healthy' => collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok'),
            'checked_at' => now()->toAtomString(),
            'checks' => $checks,
        ];
    }

    public function heartbeat(): void
    {
        Cache::put(self::SCHEDULER_HEARTBEAT_KEY, now()->timestamp, now()->addMinutes(10));
    }

    /** @return array{status: string, detail?: string} */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'failed', 'detail' => 'Database query failed.'];
        }
    }

    /** @return array{status: string, detail?: string} */
    private function cache(): array
    {
        $key = 'health:probe:'.bin2hex(random_bytes(8));

        try {
            Cache::put($key, 'ok', 30);
            $healthy = Cache::get($key) === 'ok';
            Cache::forget($key);

            return $healthy
                ? ['status' => 'ok']
                : ['status' => 'failed', 'detail' => 'Cache read-after-write failed.'];
        } catch (Throwable) {
            return ['status' => 'failed', 'detail' => 'Cache operation failed.'];
        }
    }

    /** @return array{status: string, detail?: string} */
    private function scheduler(): array
    {
        try {
            $timestamp = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
            $maximumAge = max(60, (int) config('skyguardian.health.scheduler_max_age_seconds', 180));
            if (! is_numeric($timestamp) || now()->timestamp - (int) $timestamp > $maximumAge) {
                return ['status' => 'failed', 'detail' => 'Scheduler heartbeat is stale.'];
            }

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'failed', 'detail' => 'Scheduler heartbeat is unavailable.'];
        }
    }

    /** @return array{status: string, detail?: string} */
    private function socket(string $host, int $port): array
    {
        $timeout = max(0.1, (float) config('skyguardian.health.socket_timeout_seconds', 0.5));
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errorNumber, $error, $timeout);

        if (! is_resource($socket)) {
            return ['status' => 'failed', 'detail' => 'Daemon socket is unavailable.'];
        }

        fclose($socket);

        return ['status' => 'ok'];
    }

    /** @return array{status: string, detail?: string} */
    private function disk(): array
    {
        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());
        if (! is_float($total) || ! is_float($free) || $total <= 0) {
            return ['status' => 'failed', 'detail' => 'Disk statistics are unavailable.'];
        }

        $usedPercent = (($total - $free) / $total) * 100;
        $maximum = max(50, min(99, (int) config('skyguardian.health.disk_max_used_percent', 90)));

        return $usedPercent < $maximum
            ? ['status' => 'ok']
            : ['status' => 'failed', 'detail' => 'Disk usage threshold exceeded.'];
    }

    /** @return array{status: string, detail?: string} */
    private function backup(): array
    {
        $path = (string) config('skyguardian.health.backup_status_path');
        $maximumAge = max(3600, (int) config('skyguardian.health.backup_max_age_seconds', 129600));

        try {
            $payload = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $createdAtValue = is_array($payload) ? ($payload['created_at'] ?? null) : null;
            if (! is_string($createdAtValue) || trim($createdAtValue) === '') {
                return ['status' => 'failed', 'detail' => 'Verified backup timestamp is missing.'];
            }

            $createdAt = Carbon::parse($createdAtValue);
            if ($createdAt->isFuture() || $createdAt->lt(now()->subSeconds($maximumAge))) {
                return ['status' => 'failed', 'detail' => 'Latest verified backup is stale.'];
            }

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'failed', 'detail' => 'Verified backup status is unavailable.'];
        }
    }
}
