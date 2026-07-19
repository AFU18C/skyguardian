<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::view('/news/sources', 'section', ['pageTitle' => 'Источники новостей', 'pageDescription' => 'Подключение и управление источниками новостного бота', 'activeSection' => 'news-sources'])->name('news.sources');
    Route::view('/news/settings', 'section', ['pageTitle' => 'Настройки новостного бота', 'pageDescription' => 'Параметры обработки и публикации новостей', 'activeSection' => 'news-settings'])->name('news.settings');
    Route::view('/alerts/sources', 'section', ['pageTitle' => 'Источники воздушной тревоги', 'pageDescription' => 'Подключение источников данных о воздушных тревогах', 'activeSection' => 'alert-sources'])->name('alerts.sources');
    Route::view('/alerts/settings', 'section', ['pageTitle' => 'Настройки бота тревог', 'pageDescription' => 'Параметры уведомлений о воздушной тревоге', 'activeSection' => 'alert-settings'])->name('alerts.settings');
    Route::view('/users', 'section', ['pageTitle' => 'Пользователи', 'pageDescription' => 'Управление доступом к панели SkyGuardian', 'activeSection' => 'users'])->name('users.index');
});
