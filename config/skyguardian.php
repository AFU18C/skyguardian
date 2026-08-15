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
        'browser_concurrency' => (int) env('SKYGUARDIAN_BET_BROWSER_CONCURRENCY', 3),
        'navigation_timeout_ms' => (int) env('SKYGUARDIAN_BET_NAVIGATION_TIMEOUT_MS', 12000),
        'browser_timeout_seconds' => (int) env('SKYGUARDIAN_BET_BROWSER_TIMEOUT', 90),
        'maximum_website_sources_per_run' => (int) env('SKYGUARDIAN_BET_MAX_WEBSITES', 20),
    ],
    'retention' => [
        'group_channel_messages_days' => (int) env('SKYGUARDIAN_MESSAGE_RETENTION_DAYS', 30),
        'failed_webhook_updates_days' => (int) env('SKYGUARDIAN_FAILED_WEBHOOK_RETENTION_DAYS', 30),
        'audit_log_days' => (int) env('SKYGUARDIAN_AUDIT_RETENTION_DAYS', 180),
    ],
    'health' => [
        'scheduler_max_age_seconds' => (int) env('SKYGUARDIAN_SCHEDULER_MAX_AGE_SECONDS', 180),
        'socket_timeout_seconds' => (float) env('SKYGUARDIAN_HEALTH_SOCKET_TIMEOUT_SECONDS', 0.5),
        'disk_max_used_percent' => (int) env('SKYGUARDIAN_DISK_MAX_USED_PERCENT', 90),
        'backup_status_path' => env('SKYGUARDIAN_BACKUP_STATUS_PATH', '/var/lib/skyguardian-backup/latest.json'),
        'backup_max_age_seconds' => (int) env('SKYGUARDIAN_BACKUP_MAX_AGE_SECONDS', 129600),
    ],
    'media' => [
        'site_upload_max_megabytes' => (int) env('SKYGUARDIAN_SITE_UPLOAD_MAX_MB', 20),
        'telegram_upload_max_megabytes' => (int) env('SKYGUARDIAN_TELEGRAM_UPLOAD_MAX_MB', 50),
    ],
];
