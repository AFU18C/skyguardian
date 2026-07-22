<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$workerFile = dirname(__DIR__) . '/bin/data-channel-worker.php';
$source = file_get_contents($workerFile);
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: cannot read data channel worker\n");
    exit(1);
}

$assertContains = static function (string $needle, string $message) use ($source): void {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assertContains('use SkyGuardian\\Worker\\RetryPolicy;', 'worker must import RetryPolicy');
$assertContains('use SkyGuardian\\Worker\\WorkerRunMetrics;', 'worker must import WorkerRunMetrics');
$assertContains("new RetryPolicy(maxAttempts: 4", 'worker must configure bounded retries');
$assertContains("new WorkerRunMetrics('data-channel-worker', \$scope)", 'worker must initialize run metrics');
$assertContains("\$api->getInfo(\$sourceRaw)", 'source peer lookup must remain present');
$assertContains("\$api->messages->getHistory", 'history lookup must remain present');
$assertContains("\$api->messages->sendMessage", 'text publication must remain present');
$assertContains("\$api->messages->sendMedia", 'media publication must remain present');
$assertContains("\$metrics->processed()", 'processed messages must be counted');
$assertContains("\$metrics->published()", 'published messages must be counted');
$assertContains("\$metrics->failed(\$exception)", 'worker errors must be counted');
$assertContains("data-channel-worker-' . \$scope . '-metrics.json", 'metrics must be persisted per scope');
$assertContains('finally {', 'lock and metrics cleanup must run from finally');

$composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
$prefix = $composer['autoload']['psr-4']['SkyGuardian\\'] ?? null;
if ($prefix !== 'src/') {
    fwrite(STDERR, "FAIL: SkyGuardian PSR-4 autoload is not configured\n");
    exit(1);
}

foreach ([
    SkyGuardian\Worker\RetryPolicy::class,
    SkyGuardian\Worker\WorkerRunMetrics::class,
] as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "FAIL: {$class} is not autoloadable\n");
        exit(1);
    }
}

echo "Worker integration tests passed\n";
