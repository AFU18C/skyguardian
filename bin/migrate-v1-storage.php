#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/storage';
$target = $root . '/storage/v1';
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) throw new RuntimeException('Cannot create v1 storage directory.');

$map = [
    'admin.json' => 'admin.json',
    'telegram-bot.json' => 'bot-config.json',
    'moderation-settings.json' => 'moderation.json',
    'telegram-accounts.json' => 'telegram_accounts.json',
    'telegram-news-accounts.json' => 'telegram_news_accounts.json',
    'telegram-news-channels.json' => 'news-channels-legacy.json',
    'telegram-alerts-channels.json' => 'alerts-channels-legacy.json',
    'telegram-news-channel-state.json' => 'news-worker-status.json',
    'telegram-alerts-channel-state.json' => 'alerts-worker-status.json',
];
$manifest = ['created_at' => gmdate(DATE_ATOM), 'copied' => [], 'skipped' => []];
foreach ($map as $from => $to) {
    $sourceFile = $source . '/' . $from;
    $targetFile = $target . '/' . $to;
    if (!is_file($sourceFile)) { $manifest['skipped'][$from] = 'source_missing'; continue; }
    if (is_file($targetFile)) { $manifest['skipped'][$from] = 'target_exists'; continue; }
    $raw = file_get_contents($sourceFile);
    if ($raw === false) throw new RuntimeException('Cannot read ' . $sourceFile);
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Invalid JSON structure in ' . $sourceFile);
    $temp = tempnam($target, '.migrate-');
    if ($temp === false || file_put_contents($temp, $raw, LOCK_EX) === false || !rename($temp, $targetFile)) throw new RuntimeException('Cannot write ' . $targetFile);
    chmod($targetFile, 0600);
    $manifest['copied'][$from] = $to;
    fwrite(STDOUT, $from . " -> " . $to . "\n");
}
file_put_contents($target . '/migration-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
chmod($target . '/migration-manifest.json', 0600);
