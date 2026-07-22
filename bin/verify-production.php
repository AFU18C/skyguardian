#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$errors = [];
$warnings = [];

$requiredFiles = [
    'vendor/autoload.php',
    'public/index.php',
    'public/worker-status.php',
    'public/worker-notifications.php',
    'bin/data-channel-worker.php',
    'bin/worker-notification-watch.php',
    'deploy/skyguardian-data-news.service',
    'deploy/skyguardian-data-alerts.service',
    'deploy/skyguardian-worker-notifications.service',
    'deploy/skyguardian-worker-notifications.timer',
];

foreach ($requiredFiles as $file) {
    if (!is_file($projectDir . '/' . $file)) {
        $errors[] = 'Missing required file: ' . $file;
    }
}

if (!is_dir($storageDir)) {
    $errors[] = 'Storage directory is missing.';
} elseif (!is_writable($storageDir)) {
    $errors[] = 'Storage directory is not writable.';
}

foreach (['news', 'alerts'] as $scope) {
    $metrics = $storageDir . '/data-channel-worker-' . $scope . '-metrics.json';
    if (!is_file($metrics)) {
        $warnings[] = $scope . ' worker has not produced metrics yet.';
        continue;
    }

    $data = json_decode((string) file_get_contents($metrics), true);
    if (!is_array($data)) {
        $errors[] = 'Invalid metrics JSON for ' . $scope . ' worker.';
        continue;
    }

    $finishedAt = isset($data['finished_at']) ? strtotime((string) $data['finished_at']) : false;
    if ($finishedAt === false) {
        $warnings[] = $scope . ' worker metrics do not contain a valid finished_at timestamp.';
    } elseif (time() - $finishedAt > 600) {
        $warnings[] = $scope . ' worker metrics are older than 10 minutes.';
    }

    if (($data['status'] ?? null) === 'error') {
        $errors[] = $scope . ' worker reports error status.';
    }
}

$notificationConfig = $storageDir . '/worker-notifications.json';
if (is_file($notificationConfig)) {
    $config = json_decode((string) file_get_contents($notificationConfig), true);
    if (!is_array($config)) {
        $errors[] = 'Notification configuration JSON is invalid.';
    } elseif (($config['enabled'] ?? false) === true) {
        if (trim((string) ($config['bot_token'] ?? '')) === '') {
            $errors[] = 'Notifications are enabled without a bot token.';
        }
        if (trim((string) ($config['chat_id'] ?? '')) === '') {
            $errors[] = 'Notifications are enabled without a chat ID.';
        }
    }
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, "WARNING: {$warning}\n");
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "SkyGuardian production verification passed.\n");
exit(0);
