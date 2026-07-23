<?php
declare(strict_types=1);

namespace SkyGuardian\Worker;

final class WorkerStatusService
{
    public function __construct(
        private readonly string $storageDir,
        private readonly int $staleAfterSeconds = 300,
    ) {
    }

    /** @return array{generated_at:string,workers:array<string,array<string,mixed>>} */
    public function overview(?int $now = null): array
    {
        $now ??= time();
        $workers = [];

        foreach (['news', 'alerts'] as $scope) {
            $workers[$scope] = $this->status($scope, $now);
        }

        return [
            'generated_at' => gmdate(DATE_ATOM, $now),
            'workers' => $workers,
        ];
    }

    /** @return array<string,mixed> */
    public function status(string $scope, ?int $now = null): array
    {
        if (!in_array($scope, ['news', 'alerts'], true)) {
            throw new \InvalidArgumentException('Unknown worker scope.');
        }

        $now ??= time();
        $metrics = $this->readJson($this->storageDir . '/data-channel-worker-' . $scope . '-metrics.json');
        $channelStates = $this->readJson($this->storageDir . '/telegram-' . $scope . '-channel-state.json');

        $finishedAt = $this->timestamp($metrics['finished_at'] ?? null);
        $age = $finishedAt === null ? null : max(0, $now - $finishedAt);
        $metricStatus = (string) ($metrics['status'] ?? 'unknown');
        $errors = $this->recentErrors($metrics, $channelStates);
        $channelsChecking = $this->countChannels($channelStates, 'checking');
        $channelsError = $this->countChannels($channelStates, 'error');

        $status = 'idle';
        if ($finishedAt === null) {
            $status = 'not_started';
        } elseif ($metricStatus === 'error' || $errors !== []) {
            $status = 'error';
        } elseif ($age !== null && $age > $this->staleAfterSeconds) {
            $status = 'stale';
        } elseif ($channelsChecking > 0) {
            $status = 'running';
        }

        $metricSummary = [
            'duration_ms' => max(0, (int) ($metrics['duration_ms'] ?? 0)),
            'processed_count' => max(0, (int) ($metrics['processed_count'] ?? 0)),
            'published_count' => max(0, (int) ($metrics['published_count'] ?? 0)),
            'retry_count' => max(0, (int) ($metrics['retry_count'] ?? 0)),
            'error_count' => max(0, (int) ($metrics['error_count'] ?? 0)),
        ];
        $channelSummary = [
            'total' => count($channelStates),
            'checking' => $channelsChecking,
            'error' => $channelsError,
        ];
        $recentErrors = array_slice($errors, 0, 10);

        return [
            'scope' => $scope,
            'status' => $status,
            'started_at' => $metrics['started_at'] ?? null,
            'finished_at' => $metrics['finished_at'] ?? null,
            'age_seconds' => $age,
            'metrics' => $metricSummary,
            'channels' => $channelSummary,
            'errors' => $recentErrors,
            // Flat compatibility fields are retained for older API consumers.
            ...$metricSummary,
            'channels_total' => $channelSummary['total'],
            'channels_checking' => $channelSummary['checking'],
            'channels_error' => $channelSummary['error'],
            'recent_errors' => $recentErrors,
        ];
    }

    /** @return array<int,array{source:string,channel_id:?string,channel_name:?string,at:?string,message:string}> */
    private function recentErrors(array $metrics, array $channelStates): array
    {
        $errors = [];
        $metricError = trim((string) ($metrics['last_error'] ?? ''));
        if ($metricError !== '') {
            $errors[] = [
                'source' => 'worker',
                'channel_id' => null,
                'channel_name' => null,
                'at' => is_string($metrics['finished_at'] ?? null) ? $metrics['finished_at'] : null,
                'message' => $this->sanitize($metricError),
            ];
        }

        foreach ($channelStates as $channelId => $state) {
            if (!is_array($state)) continue;
            $message = trim((string) ($state['worker_last_error'] ?? $state['last_error'] ?? ''));
            if ($message === '') continue;
            $errors[] = [
                'source' => 'channel',
                'channel_id' => (string) $channelId,
                'channel_name' => isset($state['channel_name']) ? (string) $state['channel_name'] : null,
                'at' => is_string($state['worker_last_check_at'] ?? null)
                    ? $state['worker_last_check_at']
                    : (is_string($state['updated_at'] ?? null) ? $state['updated_at'] : null),
                'message' => $this->sanitize($message),
            ];
        }

        return $errors;
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/\b\d{6,12}:[A-Za-z0-9_-]{20,}\b/', '[REDACTED_TOKEN]', $message) ?? $message;
        $message = preg_replace('/(?i)(api[_ -]?hash|password|secret)\s*[=:]\s*\S+/', '$1=[REDACTED]', $message) ?? $message;
        return mb_substr($message, 0, 500);
    }

    private function countChannels(array $states, string $status): int
    {
        $count = 0;
        foreach ($states as $state) {
            if (!is_array($state)) continue;
            $workerStatus = (string) ($state['worker_status'] ?? $state['status'] ?? '');
            if ($workerStatus === $status) $count++;
        }
        return $count;
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') return null;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
