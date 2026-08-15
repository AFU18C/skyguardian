<?php

return [
    'timezone' => 'Europe/Kyiv',
    'limits' => [
        'technical_accounts' => 20,
        'sources' => 40,
        'global_concurrent_operations' => 5,
        'account_concurrent_operations' => 2,
    ],
    'telethon' => [
        'host' => env('SKYGUARDIAN_TELETHON_HOST', '127.0.0.1'),
        'port' => (int) env('SKYGUARDIAN_TELETHON_PORT', 8787),
        'timeout_seconds' => (int) env('SKYGUARDIAN_TELETHON_TIMEOUT', 60),
    ],
    'group_channel_telethon' => [
        'host' => env('SKYGUARDIAN_GROUP_CHANNEL_TELETHON_HOST', '127.0.0.1'),
        'port' => (int) env('SKYGUARDIAN_GROUP_CHANNEL_TELETHON_PORT', 8788),
        'timeout_seconds' => (int) env('SKYGUARDIAN_GROUP_CHANNEL_TELETHON_TIMEOUT', 180),
    ],
    'betting' => [
        'node_binary' => env('SKYGUARDIAN_NODE_BINARY', 'node'),
        'playwright_browsers_path' => env('PLAYWRIGHT_BROWSERS_PATH', ''),
        'navigation_timeout_ms' => (int) env('SKYGUARDIAN_BET_NAVIGATION_TIMEOUT_MS', 20000),
        'browser_timeout_seconds' => (int) env('SKYGUARDIAN_BET_BROWSER_TIMEOUT', 75),
    ],
];
