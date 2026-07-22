<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'public/index.php',
    'public/worker-status.php',
    'public/worker-notifications.php',
    'public/assets/worker-monitor.js',
    'public/assets/worker-monitor.css',
    'public/assets/worker-notifications.js',
    'public/assets/worker-notifications.css',
    'bin/data-channel-worker.php',
    'bin/worker-notification-watch.php',
    'deploy/skyguardian-data-news.service',
    'deploy/skyguardian-data-alerts.service',
    'deploy/skyguardian-worker-notifications.service',
    'deploy/skyguardian-worker-notifications.timer',
];

foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing release file: ' . $path);
    }
}

$index = (string) file_get_contents($root . '/public/index.php');
foreach (['data-worker-monitor', 'data-worker-notifications', 'worker-monitor.js', 'worker-notifications.js'] as $marker) {
    if (!str_contains($index, $marker)) {
        throw new RuntimeException('Missing UI marker: ' . $marker);
    }
}

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($composer['autoload']['psr-4']['SkyGuardian\\'] ?? null) !== 'src/') {
    throw new RuntimeException('SkyGuardian PSR-4 autoload is not configured.');
}

fwrite(STDOUT, "SkyGuardian release smoke test passed\n");
