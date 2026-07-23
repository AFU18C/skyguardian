<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$qrEndpoint = (string) file_get_contents($root . '/public/telegram-qr.php');
$accountsEndpoint = (string) file_get_contents($root . '/public/technical-accounts.php');
$workerMonitor = (string) file_get_contents($root . '/public/assets/worker-monitor.js');

$failures = [];

if (!str_contains($qrEndpoint, "$accountsDir . '/telegram.json'")) {
    $failures[] = 'QR endpoint must persist connected accounts to telegram.json.';
}
if (str_contains($qrEndpoint, "$accountsDir . '/' . $scope . '.json'")) {
    $failures[] = 'QR endpoint must not persist accounts to page-scoped JSON files.';
}
if (!str_contains($accountsEndpoint, "$storageDir . '/telegram.json'")) {
    $failures[] = 'Technical accounts endpoint must read the canonical telegram.json file.';
}
if (!str_contains($workerMonitor, "'/technical-accounts.php'")) {
    $failures[] = 'Frontend must persist connected account metadata through the server endpoint.';
}
if (!str_contains($workerMonitor, 'serverItems')) {
    $failures[] = 'Frontend must synchronize from server-owned account state.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Telegram account persistence regression test passed.\n";
