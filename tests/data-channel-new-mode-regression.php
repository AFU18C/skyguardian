<?php
declare(strict_types=1);

$wrapper = file_get_contents(__DIR__ . '/../bin/data-channel-worker-safe.php');
$newsService = file_get_contents(__DIR__ . '/../deploy/skyguardian-data-news.service');
$alertsService = file_get_contents(__DIR__ . '/../deploy/skyguardian-data-alerts.service');

if ($wrapper === false || $newsService === false || $alertsService === false) {
    fwrite(STDERR, "Cannot read new-only safety files\n");
    exit(1);
}

foreach ([
    "processing_start_snapshot",
    "'initialized' => false",
    "'last_message_id' => 0",
    "require __DIR__ . '/data-channel-worker.php'",
] as $needle) {
    if (!str_contains($wrapper, $needle)) {
        fwrite(STDERR, "Missing safety guard: {$needle}\n");
        exit(1);
    }
}

foreach ([$newsService, $alertsService] as $service) {
    if (!str_contains($service, 'data-channel-worker-safe.php')) {
        fwrite(STDERR, "Service bypasses new-only safety wrapper\n");
        exit(1);
    }
}

echo "New-only history replay guard passed.\n";
