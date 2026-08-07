<?php

namespace App\Providers;

use App\Services\DirectGroupChannelTelegramService;
use App\Services\GroupChannelAlertPublicationService;
use App\Services\GroupChannelTelegramService;
use App\Services\GroupedAlertTelegramService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            GroupChannelAlertPublicationService::class,
            fn ($app): GroupChannelAlertPublicationService => new GroupChannelAlertPublicationService(
                $app->make(DirectGroupChannelTelegramService::class),
            ),
        );

        if (! $this->app->environment('testing')) {
            $this->app->bind(
                GroupChannelTelegramService::class,
                GroupedAlertTelegramService::class,
            );
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
