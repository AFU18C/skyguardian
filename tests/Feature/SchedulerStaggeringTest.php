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
        $promo = $this->event('skyguardian:promo-campaign:status');

        $this->assertSame(10, $publications->repeatSeconds);
        $this->assertSame(1, $alerts->repeatSeconds);
        $this->assertSame(1, $webhooks->repeatSeconds);
        $this->assertSame(1, $promo->repeatSeconds);

        $minute = now()->startOfMinute();

        $this->travelTo($minute);
        $this->assertFalse($alerts->filtersPass($this->app));
        $this->assertFalse($webhooks->filtersPass($this->app));
        $this->assertFalse($promo->filtersPass($this->app));

        $this->travelTo($minute->copy()->addSeconds(2));
        $this->assertTrue($webhooks->filtersPass($this->app));
        $this->assertFalse($alerts->filtersPass($this->app));
        $this->assertFalse($promo->filtersPass($this->app));

        $this->travelTo($minute->copy()->addSeconds(5));
        $this->assertTrue($alerts->filtersPass($this->app));
        $this->assertFalse($webhooks->filtersPass($this->app));
        $this->assertFalse($promo->filtersPass($this->app));

        $this->travelTo($minute->copy()->addSeconds(7));
        $this->assertTrue($promo->filtersPass($this->app));
        $this->assertFalse($alerts->filtersPass($this->app));
        $this->assertFalse($webhooks->filtersPass($this->app));

        $this->travelTo($minute->copy()->addSeconds(15));
        $this->assertTrue($alerts->filtersPass($this->app));

        $this->travelTo($minute->copy()->addMinute()->addSeconds(2));
        $this->assertTrue($webhooks->filtersPass($this->app));

        $this->travelTo($minute->copy()->addMinute()->addSeconds(7));
        $this->assertTrue($promo->filtersPass($this->app));
    }

    public function test_campaign_status_workflow_does_not_duplicate_scheduler_commands(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/campaign-status.yml'));

        $this->assertStringNotContainsString('skyguardian:group-channel-publications:process', $workflow);
        $this->assertStringNotContainsString('skyguardian:promo-campaign:status', $workflow);
    }

    private function event(string $command): object
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, $command));

        $this->assertNotNull($event);

        return $event;
    }
}
