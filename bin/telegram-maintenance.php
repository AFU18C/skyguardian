#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/public/TelegramAutomation.php';
require dirname(__DIR__) . '/public/TelegramRuntimeLock.php';

$storageDir = dirname(__DIR__) . '/storage';
$runtimeLock = null;

try {
    $runtimeLock = TelegramRuntimeLock::acquire($storageDir, true);
    (new TelegramAutomation($storageDir))->runMaintenance();
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Telegram runtime is already processing state.') {
        exit(0);
    }
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $runtimeLock?->release();
}
