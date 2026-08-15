<?php

use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\BettingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GroupChannelAlertDeliveryController;
use App\Http\Controllers\Admin\GroupChannelAlertsApiCheckController;
use App\Http\Controllers\Admin\GroupChannelAlertsApiTokenController;
use App\Http\Controllers\Admin\GroupChannelAlertSettingsController;
use App\Http\Controllers\Admin\GroupChannelBulkDeleteController;
use App\Http\Controllers\Admin\GroupChannelCheckController;
use App\Http\Controllers\Admin\GroupChannelController;
use App\Http\Controllers\Admin\GroupChannelJoinRequestController;
use App\Http\Controllers\Admin\GroupChannelModuleSettingsController;
use App\Http\Controllers\Admin\GroupChannelModuleToggleController;
use App\Http\Controllers\Admin\GroupChannelPublicationController;
use App\Http\Controllers\Admin\GroupChannelTechnicalBulkDeleteController;
use App\Http\Controllers\Admin\GroupChannelWebhookRegistrationController;
use App\Http\Controllers\Admin\GroupChannelWelcomeController;
use App\Http\Controllers\Admin\MfaSettingsController;
use App\Http\Controllers\Admin\SiteLoginSettingsController;
use App\Http\Controllers\Admin\SiteMediaController;
use App\Http\Controllers\Admin\SiteMenuController;
use App\Http\Controllers\Admin\SitePageController as AdminSitePageController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\SourceController;
use App\Http\Controllers\Admin\SourcePollingSettingsController;
use App\Http\Controllers\Admin\SystemMetricsController;
use App\Http\Controllers\Admin\TelegramController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Http\Controllers\GroupChannelWebhookController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SitePageController;
use App\Models\Source;
use Illuminate\Support\Facades\Route;

Route::get('/up', HealthController::class)->name('health');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', fn () => response(
    "User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: ".route('sitemap')."\n",
    200,
    ['Content-Type' => 'text/plain; charset=UTF-8'],
))->name('robots');
Route::get('/', [SitePageController::class, 'home'])->name('home');
Route::post('/telegram/bot-api/webhook/{fingerprint}', GroupChannelWebhookController::class)
    ->where(['fingerprint' => '[a-f0-9]{64}'])
    ->name('group-channel.webhook');
// Compatibility endpoint for already registered bots. Deploy migrates Telegram
// to the secretless URL; no newly registered webhook uses this route.
Route::post('/telegram/bot-api/webhook/{fingerprint}/{legacySecret}', GroupChannelWebhookController::class)
    ->where(['fingerprint' => '[a-f0-9]{64}', 'legacySecret' => '[A-Za-z0-9]{40,64}'])
    ->name('group-channel.webhook.legacy');

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', [AuthenticatedSessionController::class, 'create'])->name('admin.login');
    Route::post('/admin/login', [AuthenticatedSessionController::class, 'store'])->name('admin.login.store');
    Route::get('/admin/mfa-challenge', [MfaChallengeController::class, 'create'])->name('admin.mfa.challenge');
    Route::post('/admin/mfa-challenge', [MfaChallengeController::class, 'store'])->name('admin.mfa.challenge.store');
});

Route::middleware(['auth', 'audit.admin', 'role.permissions'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/system/metrics', SystemMetricsController::class)->name('system.metrics');
    Route::get('/security', [MfaSettingsController::class, 'show'])->name('security.show');
    Route::post('/security/mfa/begin', [MfaSettingsController::class, 'begin'])->name('security.mfa.begin');
    Route::post('/security/mfa/enable', [MfaSettingsController::class, 'enable'])->name('security.mfa.enable');
    Route::delete('/security/mfa', [MfaSettingsController::class, 'disable'])->name('security.mfa.disable');
    Route::put('/security/users/{user}/role', [MfaSettingsController::class, 'updateRole'])
        ->middleware('role:administrator')
        ->name('security.users.role');
    Route::get('/system/backup', [BackupController::class, 'show'])->name('system.backup.show');
    Route::post('/system/backup', [BackupController::class, 'store'])
        ->middleware('throttle:3,1')
        ->name('system.backup.store');

    Route::get('/news', [SourceController::class, 'index'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.index');
    Route::put('/news/polling-settings', SourcePollingSettingsController::class)->defaults('sourceType', Source::TYPE_NEWS)->name('news.polling-settings.update');
    Route::post('/news', [SourceController::class, 'store'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.store');
    Route::put('/news/{source}', [SourceController::class, 'update'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.update');
    Route::delete('/news/{source}', [SourceController::class, 'destroy'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.destroy');
    Route::post('/news/{source}/check', [SourceController::class, 'check'])->defaults('sourceType', Source::TYPE_NEWS)->name('news.check');

    Route::get('/air-alert', [SourceController::class, 'index'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.index');
    Route::put('/air-alert/polling-settings', SourcePollingSettingsController::class)->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.polling-settings.update');
    Route::post('/air-alert', [SourceController::class, 'store'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.store');
    Route::put('/air-alert/{source}', [SourceController::class, 'update'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.update');
    Route::delete('/air-alert/{source}', [SourceController::class, 'destroy'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.destroy');
    Route::post('/air-alert/{source}/check', [SourceController::class, 'check'])->defaults('sourceType', Source::TYPE_AIR_ALERT)->name('air-alert.check');

    Route::get('/telegram', [TelegramController::class, 'index'])->name('telegram.index');
    Route::post('/telegram/apis', [TelegramController::class, 'storeApi'])->name('telegram.apis.store');
    Route::put('/telegram/apis/{telegramApi}', [TelegramController::class, 'updateApi'])->name('telegram.apis.update');
    Route::delete('/telegram/apis/{telegramApi}', [TelegramController::class, 'destroyApi'])->name('telegram.apis.destroy');
    Route::post('/telegram/accounts', [TelegramController::class, 'storeAccount'])->name('telegram.accounts.store');
    Route::put('/telegram/accounts/{account}', [TelegramController::class, 'updateAccount'])->name('telegram.accounts.update');
    Route::delete('/telegram/accounts/{account}', [TelegramController::class, 'destroyAccount'])->name('telegram.accounts.destroy');
    Route::post('/telegram/accounts/{account}/check', [TelegramController::class, 'checkAccount'])->name('telegram.accounts.check');
    Route::post('/telegram/accounts/{account}/send-code', [TelegramController::class, 'sendCode'])->name('telegram.accounts.send-code');
    Route::post('/telegram/accounts/{account}/sign-in', [TelegramController::class, 'signIn'])->name('telegram.accounts.sign-in');
    Route::post('/telegram/accounts/{account}/password', [TelegramController::class, 'signInPassword'])->name('telegram.accounts.password');
    Route::post('/telegram/accounts/{account}/qr/start', [TelegramController::class, 'startQr'])->name('telegram.accounts.qr.start');
    Route::post('/telegram/accounts/{account}/qr/wait', [TelegramController::class, 'waitQr'])->name('telegram.accounts.qr.wait');

    Route::get('/site-settings', [SiteSettingsController::class, 'index'])->name('site-settings');
    Route::put('/site-settings/general', [SiteSettingsController::class, 'updateGeneral'])->name('site-settings.general.update');
    Route::get('/site-settings/login', [SiteLoginSettingsController::class, 'edit'])->name('site-settings.login.edit');
    Route::put('/site-settings/login', [SiteLoginSettingsController::class, 'update'])->name('site-settings.login.update');
    Route::get('/site-settings/login/preview', [SiteLoginSettingsController::class, 'preview'])->name('site-settings.login.preview');
    Route::get('/site-settings/pages/create', [AdminSitePageController::class, 'create'])->name('site-settings.pages.create');
    Route::post('/site-settings/pages', [AdminSitePageController::class, 'store'])->name('site-settings.pages.store');
    Route::get('/site-settings/pages/{sitePage}/edit', [AdminSitePageController::class, 'edit'])->name('site-settings.pages.edit');
    Route::put('/site-settings/pages/{sitePage}', [AdminSitePageController::class, 'update'])->name('site-settings.pages.update');
    Route::delete('/site-settings/pages/{sitePage}', [AdminSitePageController::class, 'destroy'])->name('site-settings.pages.destroy');
    Route::post('/site-settings/pages/{sitePage}/duplicate', [AdminSitePageController::class, 'duplicate'])->name('site-settings.pages.duplicate');
    Route::get('/site-settings/pages/{sitePage}/preview', [AdminSitePageController::class, 'preview'])->name('site-settings.pages.preview');
    Route::post('/site-settings/media', SiteMediaController::class)->name('site-settings.media.store');
    Route::post('/site-settings/menu', [SiteMenuController::class, 'store'])->name('site-settings.menu.store');
    Route::put('/site-settings/menu/{siteMenuItem}', [SiteMenuController::class, 'update'])->name('site-settings.menu.update');
    Route::delete('/site-settings/menu/{siteMenuItem}', [SiteMenuController::class, 'destroy'])->name('site-settings.menu.destroy');

    Route::get('/group-channel', [GroupChannelController::class, 'index'])->name('group-channel');
    Route::post('/group-channel', [GroupChannelController::class, 'store'])->name('group-channel.store');
    Route::put('/group-channel/{groupChannelBot}', [GroupChannelController::class, 'update'])->name('group-channel.update');
    Route::delete('/group-channel/{groupChannelBot}', [GroupChannelController::class, 'destroy'])->name('group-channel.destroy');
    Route::post('/group-channel/{groupChannelBot}/check', GroupChannelCheckController::class)->name('group-channel.check');
    Route::post('/group-channel/{groupChannelBot}/test-message', [GroupChannelController::class, 'sendTestMessage'])->name('group-channel.test-message');
    Route::put('/group-channel/{groupChannelBot}/alerts-api-token', GroupChannelAlertsApiTokenController::class)->name('group-channel.alerts-api-token.update');
    Route::post('/group-channel/{groupChannelBot}/alerts-api-check', GroupChannelAlertsApiCheckController::class)->name('group-channel.alerts-api-check');
    Route::put('/group-channel/{groupChannelBot}/alert-settings', GroupChannelAlertSettingsController::class)->name('group-channel.alert-settings.update');
    Route::post('/group-channel/{groupChannelBot}/alert-events/{alertEvent}/resolve', [GroupChannelAlertDeliveryController::class, 'resolveEvent'])->name('group-channel.alert-events.resolve');
    Route::post('/group-channel/{groupChannelBot}/alert-cards/{alertCard}/resolve', [GroupChannelAlertDeliveryController::class, 'resolveCard'])->name('group-channel.alert-cards.resolve');
    Route::post('/group-channel/{groupChannelBot}/webhook', GroupChannelWebhookRegistrationController::class)->name('group-channel.webhook.register');
    Route::put('/group-channel/{groupChannelBot}/modules', [GroupChannelController::class, 'updateModules'])->name('group-channel.modules.update');
    Route::patch('/group-channel/{groupChannelBot}/modules/{module}', GroupChannelModuleToggleController::class)->name('group-channel.modules.toggle');
    Route::put('/group-channel/{groupChannelBot}/module-settings', GroupChannelModuleSettingsController::class)->name('group-channel.module-settings.update');
    Route::post('/group-channel/{groupChannelBot}/welcome-photo', [GroupChannelWelcomeController::class, 'update'])->name('group-channel.welcome-photo.update');
    Route::delete('/group-channel/{groupChannelBot}/welcome-photo', [GroupChannelWelcomeController::class, 'destroy'])->name('group-channel.welcome-photo.destroy');
    Route::post('/group-channel/{groupChannelBot}/join-requests/{joinRequest}/approve', [GroupChannelJoinRequestController::class, 'approve'])->name('group-channel.join-requests.approve');
    Route::post('/group-channel/{groupChannelBot}/join-requests/{joinRequest}/decline', [GroupChannelJoinRequestController::class, 'decline'])->name('group-channel.join-requests.decline');
    Route::post('/group-channel/{groupChannelBot}/publications', [GroupChannelPublicationController::class, 'store'])->name('group-channel.publications.store');
    Route::post('/group-channel/{groupChannelBot}/publications/{publication}/send', [GroupChannelPublicationController::class, 'send'])->name('group-channel.publications.send');
    Route::post('/group-channel/{groupChannelBot}/publications/{publication}/resolve', [GroupChannelPublicationController::class, 'resolve'])->name('group-channel.publications.resolve');
    Route::delete('/group-channel/{groupChannelBot}/publications/{publication}', [GroupChannelPublicationController::class, 'destroy'])->name('group-channel.publications.destroy');
    Route::post('/group-channel/{groupChannelBot}/bulk-delete/preview', [GroupChannelBulkDeleteController::class, 'preview'])->name('group-channel.bulk-delete.preview');
    Route::post('/group-channel/{groupChannelBot}/bulk-delete/execute', [GroupChannelBulkDeleteController::class, 'execute'])->name('group-channel.bulk-delete.execute');
    Route::post('/group-channel/{groupChannelBot}/technical-delete/preview', [GroupChannelTechnicalBulkDeleteController::class, 'preview'])->name('group-channel.technical-delete.preview');
    Route::post('/group-channel/{groupChannelBot}/technical-delete/execute', [GroupChannelTechnicalBulkDeleteController::class, 'execute'])->name('group-channel.technical-delete.execute');

    Route::get('/betting', [BettingController::class, 'index'])->name('betting.index');
    Route::post('/betting/search', [BettingController::class, 'search'])->middleware('throttle:3,1')->name('betting.search');
    Route::put('/betting/settings', [BettingController::class, 'updateSettings'])->name('betting.settings.update');
    Route::delete('/betting/archive', [BettingController::class, 'clearArchive'])->name('betting.archive.clear');
    Route::post('/betting/{bet}/approve', [BettingController::class, 'approve'])->name('betting.approve');
    Route::post('/betting/{bet}/resolve-publication', [BettingController::class, 'resolvePublication'])->name('betting.resolve-publication');
    Route::post('/betting/{bet}/reject', [BettingController::class, 'reject'])->name('betting.reject');
    Route::put('/betting/{bet}', [BettingController::class, 'update'])->name('betting.update');
    Route::post('/betting/{bet}/check-result', [BettingController::class, 'checkResult'])->name('betting.check-result');
    Route::post('/betting/{bet}/send-result', [BettingController::class, 'sendResult'])->name('betting.send-result');
    Route::post('/betting/{bet}/resolve-result-publication', [BettingController::class, 'resolveResultPublication'])->name('betting.resolve-result-publication');
});

Route::get('/{slug}', [SitePageController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('site.page');
