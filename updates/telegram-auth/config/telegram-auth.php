<?php

return [
    'bot_token' => env('TELEGRAM_AUTH_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_AUTH_BOT_USERNAME'),
    'admin_email' => env('TELEGRAM_AUTH_ADMIN_EMAIL'),
    'allowed_ids' => array_values(array_filter(array_map(
        static fn (string $id): string => trim($id),
        explode(',', (string) env('TELEGRAM_AUTH_ALLOWED_IDS', ''))
    ))),
    'max_auth_age' => (int) env('TELEGRAM_AUTH_MAX_AGE', 300),
];
