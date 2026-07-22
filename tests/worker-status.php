<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SkyGuardian\Worker\WorkerStatusService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$dir = sys_get_temp_dir() . '/skyguardian-worker-status-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create test directory');

$write = static function (string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
};

$now = 1_800_000_000;
$write($dir . '/data-channel-worker-news-metrics.json', [
    'status' => 'ok',
    'started_at' => gmdate(DATE_ATOM, $now - 5),
    'finished_at' => gmdate(DATE_ATOM, $now - 2),
    'duration_ms' => 3000,
    'processed_count' => 4,
    'published_count' => 2,
    'retry_count' => 1,
    'error_count' => 0,
]);
$write($dir . '/telegram-news-channel-state.json', [
    'channel-a' => ['status' => 'active', 'last_error' => null],
]);

$write($dir . '/data-channel-worker-alerts-metrics.json', [
    'status' => 'error',
    'started_at' => gmdate(DATE_ATOM, $now - 10),
    'finished_at' => gmdate(DATE_ATOM, $now - 8),
    'duration_ms' => 2000,
    'processed_count' => 1,
    'published_count' => 0,
    'retry_count' => 3,
    'error_count' => 1,
    'last_error' => 'Telegram token 123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef failed; password=secret123',
]);
$write($dir . '/telegram-alerts-channel-state.json', [
    'channel-b' => ['status' => 'error', 'last_error' => 'timeout'],
]);

$service = new WorkerStatusService($dir, 300);
$overview = $service->overview($now);
$news = $overview['workers']['news'];
$alerts = $overview['workers']['alerts'];

$assert($news['status'] === 'idle', 'Healthy completed worker must be idle between runs');
$assert($news['processed_count'] === 4, 'Processed metric must be exposed');
$assert($news['published_count'] === 2, 'Published metric must be exposed');
$assert($news['retry_count'] === 1, 'Retry metric must be exposed');
$assert($alerts['status'] === 'error', 'Failed worker must be classified as error');
$assert($alerts['channels_error'] === 1, 'Channel errors must be counted');
$assert(count($alerts['recent_errors']) === 2, 'Worker and channel errors must be included');
$serialized = json_encode($alerts, JSON_THROW_ON_ERROR);
$assert(!str_contains($serialized, '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef'), 'Bot token must be redacted');
$assert(!str_contains($serialized, 'secret123'), 'Password must be redacted');

$stale = new WorkerStatusService($dir, 5);
$write($dir . '/data-channel-worker-news-metrics.json', [
    'status' => 'ok',
    'finished_at' => gmdate(DATE_ATOM, $now - 60),
]);
$assert($stale->status('news', $now)['status'] === 'stale', 'Old successful run must be classified as stale');

$write($dir . '/data-channel-worker-news-metrics.json', [
    'status' => 'ok',
    'finished_at' => gmdate(DATE_ATOM, $now - 1),
]);
$write($dir . '/telegram-news-channel-state.json', [
    'channel-a' => ['status' => 'checking'],
]);
$assert($service->status('news', $now)['status'] === 'running', 'Checking channel must mark worker as running');

foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
@rmdir($dir);

echo "Worker status tests passed\n";
