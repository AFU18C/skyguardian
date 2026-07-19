<?php

use App\Http\Controllers\AlertBotSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::view('/news/sources', 'section', ['pageTitle' => 'Источники новостей', 'pageDescription' => 'Подключение и управление источниками новостного бота', 'activeSection' => 'news-sources'])->name('news.sources');
    Route::view('/news/settings', 'section', ['pageTitle' => 'Настройки новостного бота', 'pageDescription' => 'Параметры обработки и публикации новостей', 'activeSection' => 'news-settings'])->name('news.settings');

    Route::get('/alerts/sources', [AlertBotSettingsController::class, 'sources'])->name('alerts.sources');
    Route::put('/alerts/sources', [AlertBotSettingsController::class, 'updateSources'])->name('alerts.sources.update');

    Route::get('/alerts/settings', [AlertBotSettingsController::class, 'edit'])->name('alerts.settings');
    Route::put('/alerts/settings', [AlertBotSettingsController::class, 'update'])->name('alerts.settings.update');
    Route::post('/alerts/settings/telegram/send-code', [AlertBotSettingsController::class, 'sendCode'])->name('alerts.telegram.send-code');
    Route::post('/alerts/settings/telegram/confirm', [AlertBotSettingsController::class, 'confirmCode'])->name('alerts.telegram.confirm');
    Route::put('/alerts/settings/telegram/{account}', [AlertBotSettingsController::class, 'updateAccount'])->name('alerts.telegram.update');
    Route::delete('/alerts/settings/telegram/{account}/disconnect', [AlertBotSettingsController::class, 'disconnect'])->name('alerts.telegram.disconnect');
    Route::delete('/alerts/settings/telegram/{account}', [AlertBotSettingsController::class, 'destroy'])->name('alerts.telegram.destroy');

    Route::view('/users', 'section', ['pageTitle' => 'Пользователи', 'pageDescription' => 'Управление доступом к панели SkyGuardian', 'activeSection' => 'users'])->name('users.index');
});
