#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$indexFile = $projectDir . '/public/index.php';

if (!is_file($indexFile) || !is_readable($indexFile) || !is_writable($indexFile)) {
    fwrite(STDERR, "public/index.php must exist and be writable\n");
    exit(1);
}

$content = file_get_contents($indexFile);
if (!is_string($content) || $content === '') {
    fwrite(STDERR, "Unable to read public/index.php\n");
    exit(1);
}

$original = $content;

if (!str_contains($content, 'worker-monitor.css')) {
    $needle = '<link rel="stylesheet" href="assets/app.css?v=38">';
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Main stylesheet marker not found\n");
        exit(2);
    }
    $content = str_replace(
        $needle,
        $needle . PHP_EOL . '    <link rel="stylesheet" href="assets/worker-monitor.css?v=1">',
        $content,
        $count
    );
    if ($count !== 1) {
        fwrite(STDERR, "Unexpected stylesheet marker count\n");
        exit(2);
    }
}

if (!str_contains($content, 'data-worker-monitor')) {
    $needle = "                </article>\n\n            <?php elseif (\$isSources): ?>";
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Home page insertion marker not found\n");
        exit(3);
    }
    $block = <<<'HTML'
                </article>

                <section class="page-title worker-monitor-title">
                    <div>
                        <span class="eyebrow">TELEGRAM WORKERS</span>
                        <h2>Обработка каналов</h2>
                        <p>Статус обновляется автоматически каждые 15 секунд.</p>
                    </div>
                </section>
                <div class="worker-monitor" data-worker-monitor>
                    <div class="worker-monitor-message">Загрузка состояния worker’ов…</div>
                </div>

            <?php elseif ($isSources): ?>
HTML;
    $content = str_replace($needle, $block, $content, $count);
    if ($count !== 1) {
        fwrite(STDERR, "Unexpected home marker count\n");
        exit(3);
    }
}

if (!str_contains($content, 'worker-monitor.js')) {
    $needle = '<script src="assets/app.js';
    $position = strrpos($content, $needle);
    if ($position === false) {
        fwrite(STDERR, "Main script marker not found\n");
        exit(4);
    }
    $lineEnd = strpos($content, '</script>', $position);
    if ($lineEnd === false) {
        fwrite(STDERR, "Main script closing tag not found\n");
        exit(4);
    }
    $lineEnd += strlen('</script>');
    $content = substr($content, 0, $lineEnd)
        . PHP_EOL . '<script src="assets/worker-monitor.js?v=1" defer></script>'
        . substr($content, $lineEnd);
}

if ($content === $original) {
    echo "Worker monitor UI already installed\n";
    exit(0);
}

$temp = tempnam(dirname($indexFile), '.worker-monitor-index-');
if ($temp === false) {
    fwrite(STDERR, "Unable to create temporary file\n");
    exit(5);
}

try {
    if (file_put_contents($temp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary index');
    }
    chmod($temp, fileperms($indexFile) & 0777 ?: 0644);
    if (!rename($temp, $indexFile)) {
        throw new RuntimeException('Unable to replace public/index.php');
    }
} finally {
    if (is_file($temp)) @unlink($temp);
}

echo "Worker monitor UI installed\n";