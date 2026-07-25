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
        'python' => env('SKYGUARDIAN_PYTHON', base_path('.venv/bin/python')),
        'worker' => env('SKYGUARDIAN_TELETHON_WORKER', base_path('telethon/worker.py')),
        'timeout_seconds' => (int) env('SKYGUARDIAN_TELETHON_TIMEOUT', 60),
    ],
];
