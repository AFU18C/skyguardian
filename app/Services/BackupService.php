<?php

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupService
{
    private const STATUS_FILE = '/var/lib/skyguardian-backup/status.json';

    private const LATEST_FILE = '/var/lib/skyguardian-backup/latest.json';

    /**
     * @return array{
     *     state: string,
     *     started_at: string|null,
     *     finished_at: string|null,
     *     last_backup_at: string|null,
     *     size_bytes: int|null,
     *     message: string
     * }
     */
    public function status(): array
    {
        $status = $this->readJson(self::STATUS_FILE);
        $latest = $this->readJson(self::LATEST_FILE);
        $state = in_array($status['state'] ?? null, ['running', 'success', 'failed'], true)
            ? $status['state']
            : ($latest === null ? 'idle' : 'success');
        $startedAt = $this->date($status['started_at'] ?? null);

        if ($state === 'running' && $startedAt !== null) {
            $staleAfter = (new DateTimeImmutable($startedAt))->modify('+6 hours');
            if ($staleAfter < new DateTimeImmutable) {
                $state = 'failed';
            }
        }

        return [
            'state' => $state,
            'started_at' => $startedAt,
            'finished_at' => $this->date($status['finished_at'] ?? null),
            'last_backup_at' => $this->date($latest['created_at'] ?? null),
            'size_bytes' => isset($latest['size_bytes']) && is_numeric($latest['size_bytes'])
                ? max(0, (int) $latest['size_bytes'])
                : null,
            'message' => match ($state) {
                'running' => 'Создание резервной копии выполняется',
                'success' => 'Последняя копия создана успешно',
                'failed' => 'Последняя попытка завершилась ошибкой',
                default => 'Резервные копии пока не создавались',
            },
        ];
    }

    public function start(): bool
    {
        if ($this->status()['state'] === 'running') {
            return false;
        }

        $process = new Process([
            'sudo',
            '-n',
            '/usr/bin/systemctl',
            'start',
            '--no-block',
            'skyguardian-backup.service',
        ]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Backup service could not be started: '.$process->getErrorOutput());
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
