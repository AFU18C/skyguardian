<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$script = file_get_contents($root . '/public/assets/worker-monitor.js');
$styles = file_get_contents($root . '/public/assets/worker-monitor.css');
$installer = file_get_contents($root . '/bin/install-worker-monitor-ui.php');

$assert(is_string($script) && str_contains($script, "fetch('/worker-status.php'"), 'UI must request the authenticated worker status endpoint');
$assert(str_contains($script, 'setInterval'), 'UI must refresh worker status automatically');
$assert(str_contains($script, 'textContent') || str_contains($script, 'escapeHtml'), 'UI must safely render external values');
$assert(str_contains($script, 'visibilitychange'), 'UI must avoid unnecessary hidden-tab updates');
$assert(is_string($styles) && str_contains($styles, '.status-error'), 'UI must visibly distinguish worker errors');
$assert(str_contains($styles, '@media'), 'Worker cards must include responsive styles');
$assert(is_string($installer) && str_contains($installer, 'data-worker-monitor'), 'Installer must add the worker monitor container');
$assert(str_contains($installer, 'worker-monitor.css'), 'Installer must add worker monitor styles');
$assert(str_contains($installer, 'worker-monitor.js'), 'Installer must add worker monitor script');
$assert(str_contains($installer, 'if (!str_contains'), 'Installer must be idempotent');
$assert(str_contains($installer, 'tempnam'), 'Installer must replace the template atomically');

echo "Worker monitor UI tests passed\n";