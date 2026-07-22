<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Worker\RetryPolicy;
use SkyGuardian\Worker\WorkerRunMetrics;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$policy = new RetryPolicy(maxAttempts: 3, baseDelayMilliseconds: 1, maxDelayMilliseconds: 5);
$assert($policy->isRetryable(new RuntimeException('FLOOD_WAIT_12')), 'FloodWait must be retryable');
$assert($policy->floodWaitSeconds(new RuntimeException('FLOOD_WAIT_12')) === 12, 'FloodWait seconds must be parsed');
$assert($policy->delayMilliseconds(new RuntimeException('timeout'), 1) === 1, 'First retry delay must use base delay');
$assert($policy->delayMilliseconds(new RuntimeException('timeout'), 3) === 4, 'Retry delay must grow exponentially');
$assert(!$policy->isRetryable(new InvalidArgumentException('bad destination')), 'Permanent validation errors must not retry');

$attempts = 0;
$retries = 0;
$result = $policy->run(
    static function () use (&$attempts): string {
        $attempts++;
        if ($attempts < 3) {
            throw new RuntimeException('network timeout');
        }
        return 'ok';
    },
    static function () use (&$retries): void {
        $retries++;
    },
);
$assert($result === 'ok', 'Retry operation must eventually return its value');
$assert($attempts === 3, 'Operation must use the configured number of attempts');
$assert($retries === 2, 'Retry callback must run before repeated attempts');

$metrics = new WorkerRunMetrics('data-channel-worker', 'news', 1000.0);
$metrics->processed(3);
$metrics->published(2);
$metrics->retried();
$snapshot = $metrics->snapshot(1001.25);
$assert($snapshot['status'] === 'ok', 'Successful metrics must have ok status');
$assert($snapshot['duration_ms'] === 1250, 'Duration must be recorded in milliseconds');
$assert($snapshot['processed_count'] === 3, 'Processed count must be recorded');
$assert($snapshot['published_count'] === 2, 'Published count must be recorded');
$assert($snapshot['retry_count'] === 1, 'Retry count must be recorded');

$metrics->failed(new RuntimeException('temporary failure'));
$failed = $metrics->snapshot(1002.0);
$assert($failed['status'] === 'error', 'Failed metrics must have error status');
$assert($failed['error_count'] === 1, 'Error count must be recorded');
$assert(str_contains((string) $failed['last_error'], 'temporary failure'), 'Last error must be retained');

echo "Worker resilience tests passed\n";
