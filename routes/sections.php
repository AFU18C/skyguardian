<?php

use App\Http\Controllers\AlertBotSettingsController;
use App\Http\Controllers\AlertSourceController;
use App\Http\Controllers\BotProfileController;
use App\Http\Controllers\GroupManagementController;
use App\Http\Controllers\NewsBotSettingsController;
use App\Http\Controllers\NewsSourceController;
use App\Http\Controllers\SourcePowerController;
use App\Http\Controllers\TechnicalAccountPowerController;
use App\Http\Controllers\TelegramDependencyController;
use App\Http\Controllers\TelegramWelcomeController;
use App\Http\Middleware\SourcePowerUiMiddleware;
use App\Http\Middleware\SkyGuardianUiMiddleware;
use App\Http\Middleware\TechnicalAccountPowerUiMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', SourcePowerUiMiddleware::class, TechnicalAccountPowerUiMiddleware::class, SkyGuardianUiMiddleware::class])->group(function (): void {
    Route::get('/news/sources', [NewsSourceController::class, 'index'])->name('news.sources');
    Route::post('/news/sources', [NewsSourceController::class, 'store'])->name('news.sources.store');
    Route::put('/news/sources/{newsSource}', [NewsSourceController::class, 'update'])->name('news.sources.update');
    Route::delete('/news/sources/{newsSource}', [NewsSourceController::class, 'destroy'])->name('news.sources.destroy');
    Route::post('/news/sources/{newsSource}/check', [NewsSourceController::class, 'check'])->name('news.sources.check');
    Route::post('/news/sources/{newsSource}/power', [SourcePowerController::class, 'news'])->name('news.sources.power');
    Route::get('/news/settings', [NewsBotSettingsController::class, 'edit'])->name('news.settings');
    Route::put('/news/settings', [BotProfileController::class, 'updateNews'])->name('news.settings.update');
    Route::post('/news/settings/apis', [NewsBotSettingsController::class, 'storeApi'])->name('news.telegram-api.store');
    Route::put('/news/settings/apis/{newsTelegramApi}', [NewsBotSettingsController::class, 'updateApi'])->name('news.telegram-api.update');
    Route::delete('/news/settings/apis/{newsTelegramApi}', [TelegramDependencyController::class, 'destroyNewsApi'])->name('news.telegram-api.destroy');
    Route::post('/news/settings/telegram/send-code', [NewsBotSettingsController::class, 'sendCode'])->name('news.telegram.send-code');
    Route::post('/news/settings/telegram/confirm', [NewsBotSettingsController::class, 'confirmCode'])->name('news.telegram.confirm');
    Route::put('/news/settings/telegram/{newsAccount}', [NewsBotSettingsController::class, 'updateAccount'])->name('news.telegram.update');
    Route::post('/news/settings/telegram/{account}/power', [TechnicalAccountPowerController::class, 'news'])->name('news.telegram.power');
    Route::delete('/news/settings/telegram/{newsAccount}/disconnect', [NewsBotSettingsController::class, 'disconnect'])->name('news.telegram.disconnect');
    Route::delete('/news/settings/telegram/{newsAccount}', [TelegramDependencyController::class, 'destroyNewsAccount'])->name('news.telegram.destroy');

    Route::get('/alerts/sources', [AlertSourceController::class, 'index'])->name('alerts.sources');
    Route::post('/alerts/sources', [AlertSourceController::class, 'store'])->name('alerts.sources.store');
    Route::put('/alerts/sources/{alertSource}', [AlertSourceController::class, 'update'])->name('alerts.sources.update');
    Route::delete('/alerts/sources/{alertSource}', [AlertSourceController::class, 'destroy'])->name('alerts.sources.destroy');
    Route::post('/alerts/sources/{alertSource}/check', [AlertSourceController::class, 'check'])->name('alerts.sources.check');
    Route::post('/alerts/sources/{alertSource}/power', [SourcePowerController::class, 'alert'])->name('alerts.sources.power');
    Route::post('/alerts/sources/{alertSource}/check-source', [AlertSourceController::class, 'checkSource'])->name('alerts.sources.check-source');
    Route::post('/alerts/sources/{alertSource}/check-destination', [AlertSourceController::class, 'checkDestination'])->name('alerts.sources.check-destination');
    Route::get('/alerts/settings', [AlertBotSettingsController::class, 'edit'])->name('alerts.settings');
    Route::put('/alerts/settings', [BotProfileController::class, 'updateAlert'])->name('alerts.settings.update');
    Route::post('/alerts/settings/apis', [AlertBotSettingsController::class, 'storeApi'])->name('alerts.telegram-api.store');
    Route::put('/alerts/settings/apis/{telegramApi}', [AlertBotSettingsController::class, 'updateApi'])->name('alerts.telegram-api.update');
    Route::delete('/alerts/settings/apis/{telegramApi}', [TelegramDependencyController::class, 'destroyAlertApi'])->name('alerts.telegram-api.destroy');
    Route::post('/alerts/settings/telegram/send-code', [AlertBotSettingsController::class, 'sendCode'])->name('alerts.telegram.send-code');
    Route::post('/alerts/settings/telegram/confirm', [AlertBotSettingsController::class, 'confirmCode'])->name('alerts.telegram.confirm');
    Route::put('/alerts/settings/telegram/{account}', [AlertBotSettingsController::class, 'updateAccount'])->name('alerts.telegram.update');
    Route::post('/alerts/settings/telegram/{account}/power', [TechnicalAccountPowerController::class, 'alert'])->name('alerts.telegram.power');
    Route::delete('/alerts/settings/telegram/{account}/disconnect', [AlertBotSettingsController::class, 'disconnect'])->name('alerts.telegram.disconnect');
    Route::delete('/alerts/settings/telegram/{account}', [TelegramDependencyController::class, 'destroyAlertAccount'])->name('alerts.telegram.destroy');

    Route::get('/users', [GroupManagementController::class, 'index'])->name('users.index');
    Route::post('/users/delete-messages', [GroupManagementController::class, 'deleteMessages'])->name('users.messages.delete');
    Route::post('/users/groups', [TelegramWelcomeController::class, 'store'])->name('users.groups.store');
    Route::put('/users/groups/{group}', [TelegramWelcomeController::class, 'update'])->name('users.groups.update');
    Route::delete('/users/groups/{group}', [TelegramWelcomeController::class, 'destroy'])->name('users.groups.destroy');
});
