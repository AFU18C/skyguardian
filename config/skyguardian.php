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
];
