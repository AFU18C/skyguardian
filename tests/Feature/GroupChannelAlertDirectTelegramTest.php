<?php

namespace Tests\Feature;

use App\Services\DirectGroupChannelTelegramService;
use App\Services\GroupChannelAlertPublicationService;
use App\Services\GroupChannelTelegramService;
use App\Services\GroupedAlertTelegramService;
use ReflectionClass;
use Tests\TestCase;

class GroupChannelAlertDirectTelegramTest extends TestCase
{
    public function test_alert_publication_bypasses_legacy_grouped_telegram_interceptor(): void
    {
        $this->app->bind(GroupChannelTelegramService::class, GroupedAlertTelegramService::class);

        $service = $this->app->make(GroupChannelAlertPublicationService::class);
        $property = (new ReflectionClass($service))->getProperty('telegram');
        $property->setAccessible(true);

        $this->assertInstanceOf(
            DirectGroupChannelTelegramService::class,
            $property->getValue($service),
        );
    }
}
