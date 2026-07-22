#!/usr/bin/env php
<?php
declare(strict_types=1);

use SkyGuardian\Notification\TelegramBotSender;
use SkyGuardian\Notification\WorkerAlertService;
use SkyGuardian\Worker\WorkerStatusService;

$projectDir = dirname(__DIR__);
$autoload = $projectDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php missing\n");
    exit(1);
}
require_once $autoload;

$storageDir = $projectDir . '/storage';
$statusService = new WorkerStatusService($storageDir, staleAfterSeconds: 300);
$alerts = new WorkerAlertService($storageDir, cooldownSeconds: 900);
$sender = new TelegramBotSender();
$stateFile = $storageDir . '/worker-notification-watch-state.json';

$readJson = static function (string $file): array {
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
};
$writeJson = static function (string $file, array $data) use ($storageDir): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = tempnam($storageDir, '.watch-state-');
    if ($temp === false) throw new RuntimeException('Cannot create watcher state temp file.');
    try {
        if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Cannot write watcher state.');
        chmod($temp, 0600);
        if (!rename($temp, $file)) throw new RuntimeException('Cannot replace watcher state.');
        chmod($file, 0600);
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
};

$previous = $readJson($stateFile);
$current = [];
$exitCode = 0;

foreach (['news', 'alerts'] as $scope) {
    $status = $statusService->status($scope);
    $currentStatus = (string) ($status['status'] ?? 'not_started');
    $previousStatus = (string) ($previous[$scope]['status'] ?? 'not_started');
    $current[$scope] = ['status' => $currentStatus, 'checked_at' => gmdate(DATE_ATOM)];

    if (in_array($currentStatus, ['error', 'stale'], true)) {
        $severity = $currentStatus === 'error' ? 'critical' : 'warning';
        $details = [];
        foreach ((array) ($status['recent_errors'] ?? []) as $error) {
            if (is_array($error) && trim((string) ($error['message'] ?? '')) !== '') {
                $details[] = trim((string) $error['message']);
            }
        }
        $message = $currentStatus === 'stale'
            ? 'Worker давно не обновлял метрики. Возраст: ' . (int) ($status['age_seconds'] ?? 0) . ' сек.'
            : 'Worker завершился с ошибкой.';
        if ($details !== []) {
            $message .= "\n" . implode("\n", array_slice(array_unique($details), 0, 3));
        }
        $result = $alerts->notify($scope, $severity, $message, $sender);
        if (($result['reason'] ?? null) === 'delivery_failed') $exitCode = 1;
    } elseif (in_array($previousStatus, ['error', 'stale'], true) && in_array($currentStatus, ['idle', 'running'], true)) {
        $alerts->notify($scope, 'recovery', 'Worker снова работает. Текущий статус: ' . $currentStatus . '.', $sender);
    }
}

$writeJson($stateFile, $current);
exit($exitCode);
