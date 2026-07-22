<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'public/assets/worker-notifications.js',
    'public/assets/worker-notifications.css',
    'deploy/worker-notifications-panel.php',
    'bin/install-worker-notifications-ui.php',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file) || filesize($root . '/' . $file) < 20) {
        fwrite(STDERR, "FAIL: missing {$file}\n"); exit(1);
    }
}
$js = file_get_contents($root . '/public/assets/worker-notifications.js');
$css = file_get_contents($root . '/public/assets/worker-notifications.css');
$panel = file_get_contents($root . '/deploy/worker-notifications-panel.php');
$installer = file_get_contents($root . '/bin/install-worker-notifications-ui.php');
foreach ([
    [$js, '/worker-notifications.php', 'UI must call notification API'],
    [$js, "operation: 'test'", 'UI must support test notification'],
    [$js, 'credentials: \'same-origin\'', 'UI must use same-origin credentials'],
    [$panel, 'data-worker-notifications', 'panel root missing'],
    [$panel, 'data-notification-journal', 'journal missing'],
    [$panel, "\$_SESSION['csrf_token']", 'CSRF token missing'],
    [$css, '.notification-log.failed', 'failed state style missing'],
    [$installer, 'Worker notifications UI already installed', 'installer must be idempotent'],
] as [$haystack, $needle, $message]) {
    if (!str_contains((string) $haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n"); exit(1);
    }
}
echo "Worker notifications UI tests passed\n";
