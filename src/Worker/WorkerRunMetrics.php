<?php
declare(strict_types=1);

namespace SkyGuardian\Worker;

final class WorkerRunMetrics
{
    private readonly float $startedAt;
    private int $processed = 0;
    private int $published = 0;
    private int $errors = 0;
    private int $retries = 0;
    private ?string $lastError = null;

    public function __construct(
        private readonly string $worker,
        private readonly string $scope,
        ?float $startedAt = null,
    ) {
        $this->startedAt = $startedAt ?? microtime(true);
    }

    public function processed(int $count = 1): void
    {
        $this->processed += max(0, $count);
    }

    public function published(int $count = 1): void
    {
        $this->published += max(0, $count);
    }

    public function retried(): void
    {
        $this->retries++;
    }

    public function failed(\Throwable|string $error): void
    {
        $this->errors++;
        $message = $error instanceof \Throwable
            ? $error::class . ': ' . $error->getMessage()
            : $error;
        $this->lastError = mb_substr($message, 0, 500);
    }

    public function snapshot(?float $finishedAt = null): array
    {
        $finishedAt ??= microtime(true);
        $duration = max(0.0, $finishedAt - $this->startedAt);

        return [
            'worker' => $this->worker,
            'scope' => $this->scope,
            'status' => $this->errors > 0 ? 'error' : 'ok',
            'started_at' => gmdate(DATE_ATOM, (int) $this->startedAt),
            'finished_at' => gmdate(DATE_ATOM, (int) $finishedAt),
            'duration_ms' => (int) round($duration * 1000),
            'processed_count' => $this->processed,
            'published_count' => $this->published,
            'error_count' => $this->errors,
            'retry_count' => $this->retries,
            'last_error' => $this->lastError,
        ];
    }
}
