#!/usr/bin/env php
<?php
declare(strict_types=1);

$scope = (string) ($argv[1] ?? '');
if (!in_array($scope, ['news', 'alerts'], true)) {
    fwrite(STDERR, "Usage: data-channel-worker-runtime.php <news|alerts>\n");
    exit(2);
}

$root = dirname(__DIR__);
$storage = $root . '/storage';
$canonicalAccounts = $storage . '/technical-accounts/telegram.json';
$legacyAccounts = $scope === 'news'
    ? $storage . '/telegram-news-accounts.json'
    : $storage . '/telegram-accounts.json';
$canonicalSessions = $storage . '/telegram-sessions';
$workerSessions = $scope === 'news'
    ? $storage . '/telegram-news-sessions'
    : $canonicalSessions;

foreach ([$storage, dirname($canonicalAccounts), $canonicalSessions, $workerSessions] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot prepare Telegram runtime directory: ' . $directory);
    }
}

$lockPath = $storage . '/data-channel-runtime-compat.lock';
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX)) {
    throw new RuntimeException('Cannot lock Telegram runtime compatibility state.');
}

try {
    $raw = is_file($canonicalAccounts) ? file_get_contents($canonicalAccounts) : '[]';
    $accounts = json_decode((string) $raw, true);
    $accounts = is_array($accounts) ? array_values(array_filter($accounts, 'is_array')) : [];

    $tmp = $legacyAccounts . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $legacyAccounts)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot publish canonical technical accounts to worker runtime.');
    }
    @chmod($legacyAccounts, 0660);

    foreach ($accounts as $account) {
        $id = trim((string) ($account['id'] ?? ''));
        $apiId = trim((string) ($account['api_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $id) || $apiId === '') continue;

        $sessionKey = hash('sha256', $id . ':' . $apiId);
        $canonicalPrefix = $canonicalSessions . '/account-' . $sessionKey . '.madeline';
        $workerPrefix = $workerSessions . '/' . $id . '.madeline';

        foreach (glob($canonicalPrefix . '*') ?: [] as $source) {
            $suffix = substr($source, strlen($canonicalPrefix));
            $target = $workerPrefix . $suffix;
            if (file_exists($target) || is_link($target)) {
                if (is_link($target) && readlink($target) === $source) continue;
                @unlink($target);
            }
            @symlink($source, $target);
        }
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

require __DIR__ . '/data-channel-worker-safe.php';
