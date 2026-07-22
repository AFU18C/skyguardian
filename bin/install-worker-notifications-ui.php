#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$index = $root . '/public/index.php';
$fragment = $root . '/deploy/worker-notifications-panel.php';
$content = @file_get_contents($index);
$panel = @file_get_contents($fragment);
if (!is_string($content) || !is_string($panel)) {
    fwrite(STDERR, "Required UI files are unavailable\n");
    exit(1);
}
$original = $content;

if (!str_contains($content, 'worker-notifications.css')) {
    $marker = str_contains($content, 'worker-monitor.css?v=1')
        ? '<link rel="stylesheet" href="assets/worker-monitor.css?v=1">'
        : '<link rel="stylesheet" href="assets/app.css?v=38">';
    if (!str_contains($content, $marker)) exit(2);
    $content = str_replace($marker, $marker . "\n    <link rel=\"stylesheet\" href=\"assets/worker-notifications.css?v=1\">", $content, $count);
    if ($count !== 1) exit(2);
}

if (!str_contains($content, 'data-worker-notifications')) {
    $marker = "                </div>\n\n            <?php elseif (\$isSources): ?>";
    $position = strpos($content, $marker);
    if ($position === false) exit(3);
    $content = substr($content, 0, $position) . "                </div>\n\n" . rtrim($panel) . "\n\n            <?php elseif (\$isSources): ?>" . substr($content, $position + strlen($marker));
}

if (!str_contains($content, 'worker-notifications.js')) {
    $marker = '<script src="assets/worker-monitor.js?v=1" defer></script>';
    if (!str_contains($content, $marker)) exit(4);
    $content = str_replace($marker, $marker . "\n<script src=\"assets/worker-notifications.js?v=1\" defer></script>", $content, $count);
    if ($count !== 1) exit(4);
}

if ($content === $original) {
    echo "Worker notifications UI already installed\n";
    exit(0);
}
$temp = tempnam(dirname($index), '.notify-ui-');
if ($temp === false || file_put_contents($temp, $content, LOCK_EX) === false) exit(5);
chmod($temp, fileperms($index) & 0777 ?: 0644);
if (!rename($temp, $index)) {
    @unlink($temp);
    exit(5);
}
echo "Worker notifications UI installed\n";
