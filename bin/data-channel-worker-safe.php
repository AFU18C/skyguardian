#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectDir = dirname(__DIR__);
$storageDir = $projectDir . '/storage';
$scope = (string) ($argv[1] ?? '');

if (!in_array($scope, ['news', 'alerts'], true)) {
    fwrite(STDERR, "Usage: data-channel-worker-safe.php <news|alerts>\n");
    exit(2);
}

$channelsFile = $storageDir . '/telegram-' . $scope . '-channels.json';
$stateFile = $storageDir . '/telegram-' . $scope . '-channel-state.json';

$readJson = static function (string $file): array {
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
};

$writeJson = static function (string $file, array $data) use ($storageDir): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temp = tempnam($storageDir, '.safe-state-');
    if ($temp === false) throw new RuntimeException('Cannot create state temp file');
    try {
        if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Cannot write state');
        chmod($temp, 0600);
        if (!rename($temp, $file)) throw new RuntimeException('Cannot replace state');
        chmod($file, 0600);
    } finally {
        if (is_file($temp)) @unlink($temp);
    }
};

$channels = array_values($readJson($channelsFile));
$states = $readJson($stateFile);
$changed = false;

foreach ($channels as $channel) {
    if (!is_array($channel)) continue;
    $id = (string) ($channel['id'] ?? '');
    if ($id === '') continue;

    $startMode = (string) ($channel['processing_start'] ?? 'new');
    $state = is_array($states[$id] ?? null) ? $states[$id] : [];
    $snapshot = (string) ($state['processing_start_snapshot'] ?? '');
    $lastId = max(0, (int) ($state['last_message_id'] ?? 0));
    $initialized = (bool) ($state['initialized'] ?? false);

    // A channel configured for "only new" must never replay history. If the
    // state is missing, corrupt, or was created under a different start mode,
    // force a clean watermark initialization before the real worker runs.
    if ($startMode === 'new' && (!$initialized || $lastId <= 0 || $snapshot !== 'new')) {
        $states[$id] = array_merge($state, [
            'initialized' => false,
            'last_message_id' => 0,
            'processing_start_snapshot' => 'new',
            'history_replay_blocked_at' => gmdate(DATE_ATOM),
            'worker_last_error' => null,
        ]);
        $changed = true;
        continue;
    }

    if ($snapshot !== $startMode) {
        $states[$id] = array_merge($state, [
            'initialized' => false,
            'last_message_id' => 0,
            'processing_start_snapshot' => $startMode,
            'worker_last_error' => null,
        ]);
        $changed = true;
    }
}

if ($changed) {
    $writeJson($stateFile, $states);
}

require __DIR__ . '/data-channel-worker.php';

// Persist the active mode after every successful cycle so changing the option
// is explicit and cannot accidentally reuse an incompatible cursor.
$states = $readJson($stateFile);
$postChanged = false;
foreach ($channels as $channel) {
    if (!is_array($channel)) continue;
    $id = (string) ($channel['id'] ?? '');
    if ($id === '' || !isset($states[$id]) || !is_array($states[$id])) continue;
    $startMode = (string) ($channel['processing_start'] ?? 'new');
    if (($states[$id]['processing_start_snapshot'] ?? null) !== $startMode) {
        $states[$id]['processing_start_snapshot'] = $startMode;
        $postChanged = true;
    }
}
if ($postChanged) {
    $writeJson($stateFile, $states);
}
