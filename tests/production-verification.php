<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$script = $projectDir . '/bin/verify-production.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(is_file($script), 'production verification command exists');
$content = (string) file_get_contents($script);

foreach ([
    'vendor/autoload.php',
    'public/worker-status.php',
    'public/worker-notifications.php',
    'skyguardian-data-news.service',
    'skyguardian-data-alerts.service',
    'skyguardian-worker-notifications.timer',
    'is_writable',
    'worker reports error status',
    'Notifications are enabled without a bot token',
    'SkyGuardian production verification passed',
] as $needle) {
    $assert(str_contains($content, $needle), 'verification command checks: ' . $needle);
}

$assert(str_contains($content, 'exit(1)'), 'verification command fails on errors');
$assert(str_contains($content, 'WARNING:'), 'verification command distinguishes warnings');

fwrite(STDOUT, "Production verification contract passed.\n");
