<?php

use App\Http\Controllers\AlertBotSettingsController;
use App\Http\Controllers\AlertSourceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::view('/news/sources', 'section', ['pageTitle' => 'Источники новостей', 'pageDescription' => 'Подключение и управление источниками новостного бота', 'activeSection' => 'news-sources'])->name('news.sources');
    Route::view('/news/settings', 'section', ['pageTitle' => 'Настройки новостного бота', 'pageDescription' => 'Параметры обработки и публикации новостей', 'activeSection' => 'news-settings'])->name('news.settings');

    Route::get('/alerts/sources', [AlertSourceController::class, 'index'])->name('alerts.sources');
    Route::post('/alerts/sources', [AlertSourceController::class, 'store'])->name('alerts.sources.store');
    Route::put('/alerts/sources/{alertSource}', [AlertSourceController::class, 'update'])->name('alerts.sources.update');
    Route::delete('/alerts/sources/{alertSource}', [AlertSourceController::class, 'destroy'])->name('alerts.sources.destroy');
    Route::post('/alerts/sources/{alertSource}/check', [AlertSourceController::class, 'check'])->name('alerts.sources.check');
    Route::post('/alerts/sources/{alertSource}/check-source', [AlertSourceController::class, 'checkSource'])->name('alerts.sources.check-source');
    Route::post('/alerts/sources/{alertSource}/check-destination', [AlertSourceController::class, 'checkDestination'])->name('alerts.sources.check-destination');

    Route::get('/alerts/settings', [AlertBotSettingsController::class, 'edit'])->name('alerts.settings');
    Route::put('/alerts/settings', [AlertBotSettingsController::class, 'update'])->name('alerts.settings.update');

    Route::post('/alerts/settings/apis', [AlertBotSettingsController::class, 'storeApi'])->name('alerts.telegram-api.store');
    Route::put('/alerts/settings/apis/{telegramApi}', [AlertBotSettingsController::class, 'updateApi'])->name('alerts.telegram-api.update');
    Route::delete('/alerts/settings/apis/{telegramApi}', [AlertBotSettingsController::class, 'destroyApi'])->name('alerts.telegram-api.destroy');

    Route::post('/alerts/settings/telegram/send-code', [AlertBotSettingsController::class, 'sendCode'])->name('alerts.telegram.send-code');
    Route::post('/alerts/settings/telegram/confirm', [AlertBotSettingsController::class, 'confirmCode'])->name('alerts.telegram.confirm');
    Route::put('/alerts/settings/telegram/{account}', [AlertBotSettingsController::class, 'updateAccount'])->name('alerts.telegram.update');
    Route::delete('/alerts/settings/telegram/{account}/disconnect', [AlertBotSettingsController::class, 'disconnect'])->name('alerts.telegram.disconnect');
    Route::delete('/alerts/settings/telegram/{account}', [AlertBotSettingsController::class, 'destroy'])->name('alerts.telegram.destroy');

    Route::view('/users', 'section', ['pageTitle' => 'Пользователи', 'pageDescription' => 'Управление доступом к панели SkyGuardian', 'activeSection' => 'users'])->name('users.index');
});
