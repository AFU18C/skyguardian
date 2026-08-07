<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerStaggeringTest extends TestCase
{
    public function test_background_tasks_are_staggered_without_changing_their_periods(): void
    {
        $publications = $this->event('skyguardian:group-channel-publications:process');
        $alerts = $this->event('skyguardian:group-channel-alerts:process');
        $webhooks = $this->event('skyguardian:group-channel-webhook-updates:process');

        $this->assertSame(10, $publications->repeatSeconds);
        $this->assertSame(1, $alerts->repeatSeconds);
        $this->assertSame(1, $webhooks->repeatSeconds);

        $minute = now()->startOfMinute();

        $this->travelTo($minute);
        $this->assertFalse($alerts->filtersPass($this->app));
        $this->assertFalse($webhooks->filtersPass($this->app));

        $this->travelTo($minute->copy()->addSeconds(2));
        $this->assertTrue($webhooks->filtersPass($this->app));
        $this->assertFalse($alerts->filtersPass($this->app));

        $this->travelTo($minute->copy()->addSeconds(5));
        $this->assertTrue($alerts->filtersPass($this->app));
        $this->assertFalse($webhooks->filtersPass($this->app));

        $this->travelTo($minute->copy()->addSeconds(15));
        $this->assertTrue($alerts->filtersPass($this->app));

        $this->travelTo($minute->copy()->addMinute()->addSeconds(2));
        $this->assertTrue($webhooks->filtersPass($this->app));
    }

    public function test_removed_promo_campaign_has_no_scheduler_event_or_workflow(): void
    {
        $promoEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) $event->command,
                'skyguardian:promo-campaign:',
            ));

        $this->assertNull($promoEvent);
        $this->assertFileDoesNotExist(base_path('.github/workflows/campaign-status.yml'));
    }

    private function event(string $command): object
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, $command));

        $this->assertNotNull($event);

        return $event;
    }
}
