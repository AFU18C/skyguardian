<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$accounts = (string) file_get_contents($root . '/public/technical-accounts.php');
$channels = (string) file_get_contents($root . '/public/data-channels.php');
$runtime = (string) file_get_contents($root . '/public/assets/data-channels-runtime.js');
$deploy = (string) file_get_contents($root . '/deploy/update-vps.sh');
$checks = [
    [$accounts, "'tg:'", 'accounts must deduplicate by Telegram ID'],
    [$accounts, "'api:'", 'accounts must deduplicate by API identity'],
    [$channels, "telegram-' . $scope . '-channels.json", 'channels must use worker canonical files'],
    [$channels, 'count($items) > 10', 'server must enforce the ten-channel limit'],
    [$channels, 'FILTER_VALIDATE_INT', 'server must validate polling frequency'],
    [$channels, "empty($account['connected'])", 'server must reject disconnected accounts'],
    [$channels, 'flock($lock, LOCK_EX)', 'channel writes must be serialized'],
    [$runtime, '/data-channels.php?scope=', 'browser must load server-owned channels'],
    [$runtime, 'server.length === 0 && local.length > 0', 'legacy browser data must migrate once'],
    [$deploy, 'data-channels-runtime.js', 'deployment must inject channel runtime'],
];
foreach ($checks as [$haystack, $needle, $message]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "Runtime state regression: {$message}.\n");
        exit(1);
    }
}
echo "Runtime state authority test passed.\n";
