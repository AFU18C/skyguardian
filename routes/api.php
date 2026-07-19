<?php

use App\Http\Controllers\TelegramWelcomeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/welcome/{bot}', TelegramWelcomeWebhookController::class)
    ->whereIn('bot', ['news', 'alerts'])
    ->name('telegram.welcome.webhook');
