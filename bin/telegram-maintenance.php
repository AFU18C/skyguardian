#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/public/TelegramAutomation.php';

try {
    (new TelegramAutomation(dirname(__DIR__) . '/storage'))->runMaintenance();
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . date(DATE_ATOM) . '] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
