#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/storage';
$target = $root . '/storage/v1';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
    throw new RuntimeException('Cannot create v1 storage directory.');
}

$map = [
    'admin.json' => 'admin.json',
    'telegram-bot.json' => 'bot-config.json',
    'moderation-settings.json' => 'moderation-settings.json',
    'telegram-accounts.json' => 'telegram-accounts.json',
    'telegram-news-accounts.json' => 'telegram-news-accounts.json',
    'telegram-news-channels.json' => 'news-channels.json',
    'telegram-alerts-channels.json' => 'alerts-channels.json',
    'telegram-news-channel-state.json' => 'news-worker-status.json',
    'telegram-alerts-channel-state.json' => 'alerts-worker-status.json',
];

foreach ($map as $from => $to) {
    $sourceFile = $source . '/' . $from;
    $targetFile = $target . '/' . $to;
    if (!is_file($sourceFile) || is_file($targetFile)) {
        continue;
    }
    $raw = file_get_contents($sourceFile);
    if ($raw === false) {
        throw new RuntimeException('Cannot read ' . $sourceFile);
    }
    json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (file_put_contents($targetFile, $raw, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write ' . $targetFile);
    }
    chmod($targetFile, 0600);
    fwrite(STDOUT, $from . " -> " . $to . "\n");
}
