#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/public/TelegramAutomation.php';

$automation = new TelegramAutomation(dirname(__DIR__) . '/storage');
$offsetsFile = dirname(__DIR__) . '/storage/telegram-polling-offsets.json';
$offsets = is_file($offsetsFile) ? json_decode((string)file_get_contents($offsetsFile), true) : [];
$offsets = is_array($offsets) ? $offsets : [];

foreach ($automation->pollingConfigs() as $config) {
    $id = (string)$config['id'];
    try {
        $updates = $automation->api((string)$config['bot_token'], 'getUpdates', [
            'offset' => (int)($offsets[$id] ?? 0),
            'timeout' => 20,
            'allowed_updates' => json_encode(['message', 'callback_query', 'chat_member'], JSON_UNESCAPED_SLASHES),
        ]);
        foreach ((array)$updates as $update) {
            if (!is_array($update)) continue;
            $automation->process($config, $update);
            $offsets[$id] = max((int)($offsets[$id] ?? 0), (int)($update['update_id'] ?? 0) + 1);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, '[' . date(DATE_ATOM) . '] ' . $exception->getMessage() . PHP_EOL);
    }
}
$automation->runMaintenance();
file_put_contents($offsetsFile, json_encode($offsets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
@chmod($offsetsFile, 0600);
