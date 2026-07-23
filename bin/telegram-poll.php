#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/public/TelegramAutomation.php';
require dirname(__DIR__) . '/public/TelegramRuntimeLock.php';

$storageDir = dirname(__DIR__) . '/storage';
$lockFile = $storageDir . '/telegram-poll.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] Cannot open polling lock file.' . PHP_EOL);
    exit(1);
}
@chmod($lockFile, 0600);

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    exit(0);
}

$runtimeLock = null;
$automation = new TelegramAutomation($storageDir);
$offsetsFile = $storageDir . '/telegram-polling-offsets.json';
$offsets = is_file($offsetsFile) ? json_decode((string)file_get_contents($offsetsFile), true) : [];
$offsets = is_array($offsets) ? $offsets : [];

try {
    $runtimeLock = TelegramRuntimeLock::acquire($storageDir);

    foreach ($automation->pollingConfigs() as $config) {
        $id = (string)$config['id'];
        try {
            $webhookInfo = (array)$automation->api((string)$config['bot_token'], 'getWebhookInfo');
            if (trim((string)($webhookInfo['url'] ?? '')) !== '') {
                fwrite(STDERR, '[' . date(DATE_ATOM) . '] Polling skipped for ' . $id . ': webhook is active.' . PHP_EOL);
                continue;
            }

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
    $tmp = $offsetsFile . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $payload = json_encode($offsets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($payload === false || file_put_contents($tmp, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Cannot save Telegram polling offsets.');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $offsetsFile)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot apply Telegram polling offsets.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $runtimeLock?->release();
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
