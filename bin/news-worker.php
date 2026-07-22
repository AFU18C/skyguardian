#!/usr/bin/env php
<?php
declare(strict_types=1);

$worker = dirname(__DIR__) . '/bin/data-channel-worker.php';
if (!is_file($worker)) {
    fwrite(STDERR, "data-channel-worker.php missing\n");
    exit(1);
}
while (true) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' news', $code);
    if ($code !== 0) error_log('News worker pass failed with code ' . $code);
    sleep(5);
}
