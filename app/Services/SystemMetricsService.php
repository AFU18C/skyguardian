<?php

namespace App\Services;

class SystemMetricsService
{
    /**
     * @return array{cpu: int|null, memory: int|null, disk: int|null}
     */
    public function snapshot(): array
    {
        return [
            'cpu' => $this->cpuUsage(),
            'memory' => $this->memoryUsage(),
            'disk' => $this->diskUsage(),
        ];
    }

    private function cpuUsage(): ?int
    {
        $first = $this->readCpuCounters();

        if ($first === null) {
            return null;
        }

        usleep(100_000);

        $second = $this->readCpuCounters();

        if ($second === null) {
            return null;
        }

        $idleDelta = ($second['idle'] + $second['iowait']) - ($first['idle'] + $first['iowait']);
        $totalDelta = array_sum($second) - array_sum($first);

        if ($totalDelta <= 0) {
            return null;
        }

        return $this->percentage(($totalDelta - $idleDelta) / $totalDelta * 100);
    }

    /**
     * @return array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null
     */
    private function readCpuCounters(): ?array
    {
        $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES)[0] ?? null;

        if (! is_string($line) || ! str_starts_with($line, 'cpu ')) {
            return null;
        }

        $values = array_map('intval', preg_split('/\s+/', trim(substr($line, 3))) ?: []);
        $values = array_pad($values, 8, 0);

        return [
            'user' => $values[0],
            'nice' => $values[1],
            'system' => $values[2],
            'idle' => $values[3],
            'iowait' => $values[4],
            'irq' => $values[5],
            'softirq' => $values[6],
            'steal' => $values[7],
        ];
    }

    private function memoryUsage(): ?int
    {
        $contents = @file_get_contents('/proc/meminfo');

        if (! is_string($contents)) {
            return null;
        }

        preg_match('/^MemTotal:\s+(\d+)/m', $contents, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)/m', $contents, $availableMatch);

        $total = (int) ($totalMatch[1] ?? 0);
        $available = (int) ($availableMatch[1] ?? 0);

        if ($total <= 0) {
            return null;
        }

        return $this->percentage(($total - $available) / $total * 100);
    }

    private function diskUsage(): ?int
    {
        $path = base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (! is_float($total) || ! is_float($free) || $total <= 0) {
            return null;
        }

        return $this->percentage(($total - $free) / $total * 100);
    }

    private function percentage(float $value): int
    {
        return max(0, min(100, (int) round($value)));
    }
}
