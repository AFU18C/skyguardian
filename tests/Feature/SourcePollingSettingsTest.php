<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\User;
use App\Services\SourcePollingSettings;
use App\Services\SourceProcessor;
use App\Services\SourceScheduler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SourcePollingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_icon_and_modal_are_available_in_both_source_sections(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['admin.news.index', 'Настройки автоматической проверки новостей'],
            ['admin.air-alert.index', 'Настройки автоматической проверки воздушных тревог'],
        ] as [$routeName, $label]) {
            $this->actingAs($user)
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('⚙')
                ->assertSee($label)
                ->assertSee('Автоматическая проверка источников')
                ->assertSee('Проверять наступление времени каждые')
                ->assertSee('Секунды')
                ->assertSee('Минуты')
                ->assertSee('Часы');
        }
    }

    public function test_news_and_air_alert_polling_settings_are_saved_independently(): void
    {
        $user = User::factory()->create();
        $settings = app(SourcePollingSettings::class);

        $this->actingAs($user)->put(route('admin.news.polling-settings.update'), [
            'form_context' => 'source-polling-settings',
            'source_type' => Source::TYPE_NEWS,
            'polling_enabled' => '1',
            'polling_interval_value' => '5',
            'polling_interval_unit' => 'minutes',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->put(route('admin.air-alert.polling-settings.update'), [
            'form_context' => 'source-polling-settings',
            'source_type' => Source::TYPE_AIR_ALERT,
            'polling_enabled' => '0',
            'polling_interval_value' => '10',
            'polling_interval_unit' => 'seconds',
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            'enabled' => true,
            'interval_value' => 5,
            'interval_unit' => 'minutes',
            'interval_seconds' => 300,
        ], $settings->get(Source::TYPE_NEWS));
        $this->assertSame([
            'enabled' => false,
            'interval_value' => 10,
            'interval_unit' => 'seconds',
            'interval_seconds' => 10,
        ], $settings->get(Source::TYPE_AIR_ALERT));
    }

    public function test_automatic_processor_skips_disabled_section_and_respects_its_interval(): void
    {
        $settings = app(SourcePollingSettings::class);
        $settings->update(Source::TYPE_NEWS, true, 5, 'minutes');
        $settings->update(Source::TYPE_AIR_ALERT, false, 10, 'seconds');

        $scheduler = Mockery::mock(SourceScheduler::class);
        $scheduler->shouldReceive('due')
            ->once()
            ->with(40, Source::TYPE_NEWS)
            ->andReturn(collect());
        $this->app->instance(SourceScheduler::class, $scheduler);
        $this->app->instance(SourceProcessor::class, Mockery::mock(SourceProcessor::class));

        $this->artisan('skyguardian:sources:process')->assertSuccessful();
        $this->artisan('skyguardian:sources:process')->assertSuccessful();
    }

    public function test_scheduler_respects_four_minute_section_interval(): void
    {
        $settings = app(SourcePollingSettings::class);
        $settings->update(Source::TYPE_NEWS, true, 4, 'minutes');
        $settings->update(Source::TYPE_AIR_ALERT, false, 10, 'seconds');

        $event = $this->sourceProcessingEvent();

        $this->assertTrue($event->filtersPass($this->app));

        $settings->markRun(Source::TYPE_NEWS);

        $this->assertFalse($event->filtersPass($this->app));

        $this->travel(239)->seconds();
        $this->assertFalse($event->filtersPass($this->app));

        $this->travel(1)->seconds();
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_scheduler_respects_ten_second_section_interval(): void
    {
        $settings = app(SourcePollingSettings::class);
        $settings->update(Source::TYPE_NEWS, false, 1, 'minutes');
        $settings->update(Source::TYPE_AIR_ALERT, true, 10, 'seconds');

        $event = $this->sourceProcessingEvent();

        $this->assertTrue($event->filtersPass($this->app));

        $settings->markRun(Source::TYPE_AIR_ALERT);

        $this->travel(9)->seconds();
        $this->assertFalse($event->filtersPass($this->app));

        $this->travel(1)->seconds();
        $this->assertTrue($event->filtersPass($this->app));
    }

    private function sourceProcessingEvent(): object
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'skyguardian:sources:process'));

        $this->assertNotNull($event);

        return $event;
    }
}
