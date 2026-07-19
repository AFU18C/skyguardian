<?php

use App\Http\Controllers\TelegramGroupWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/welcome/{bot}', TelegramGroupWebhookController::class)
    ->whereIn('bot', ['news', 'alerts'])
    ->name('telegram.welcome.webhook');
