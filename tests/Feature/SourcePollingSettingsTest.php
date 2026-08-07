<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Models\TechnicalAccount;
use App\Models\TelegramApi;
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
                ->assertSee('Интервал опроса раздела')
                ->assertSee('Раздел только проверяет, наступил ли индивидуальный')
                ->assertSee('Реальную проверку источника разрешает только его индивидуальный')
                ->assertSee('Минимальный интервал — 1 секунда')
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
            'polling_interval_value' => '1',
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
            'interval_value' => 1,
            'interval_unit' => 'seconds',
            'interval_seconds' => 1,
        ], $settings->get(Source::TYPE_AIR_ALERT));
    }

    public function test_automatic_processor_processes_only_requested_section(): void
    {
        $scheduler = Mockery::mock(SourceScheduler::class);
        $scheduler->shouldReceive('due')
            ->once()
            ->with(40, Source::TYPE_NEWS)
            ->andReturn(collect());
        $this->app->instance(SourceScheduler::class, $scheduler);
        $this->app->instance(SourceProcessor::class, Mockery::mock(SourceProcessor::class));

        $this->artisan('skyguardian:sources:process --type=news')->assertSuccessful();
    }

    public function test_news_and_air_alert_have_independent_scheduler_events(): void
    {
        $settings = app(SourcePollingSettings::class);
        $settings->update(Source::TYPE_NEWS, true, 4, 'minutes');
        $settings->update(Source::TYPE_AIR_ALERT, false, 1, 'seconds');

        $newsEvent = $this->sourceProcessingEvent(Source::TYPE_NEWS);
        $airAlertEvent = $this->sourceProcessingEvent(Source::TYPE_AIR_ALERT);

        $this->assertTrue($newsEvent->filtersPass($this->app));
        $this->assertFalse($airAlertEvent->filtersPass($this->app));
    }

    public function test_news_scheduler_respects_four_minute_section_interval(): void
    {
        $settings = app(SourcePollingSettings::class);
        $settings->update(Source::TYPE_NEWS, true, 4, 'minutes');
        $settings->update(Source::TYPE_AIR_ALERT, false, 1, 'seconds');

        $event = $this->sourceProcessingEvent(Source::TYPE_NEWS);

        $this->assertTrue($event->filtersPass($this->app));

        $settings->markRun(Source::TYPE_NEWS);

        $this->assertFalse($event->filtersPass($this->app));

        $this->travel(239)->seconds();
        $this->assertFalse($event->filtersPass($this->app));

        $this->travel(1)->seconds();
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_air_alert_scheduler_supports_one_second_section_interval(): void
    {
        $settings = app(SourcePollingSettings::class);
        $settings->update(Source::TYPE_NEWS, false, 1, 'minutes');
        $settings->update(Source::TYPE_AIR_ALERT, true, 1, 'seconds');

        $event = $this->sourceProcessingEvent(Source::TYPE_AIR_ALERT);

        $this->assertTrue($event->filtersPass($this->app));

        $settings->markRun(Source::TYPE_AIR_ALERT);

        $this->assertFalse($event->filtersPass($this->app));

        $this->travel(1)->seconds();
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_section_poll_only_asks_and_individual_next_check_at_decides_when_source_is_due(): void
    {
        $this->travelTo(now()->startOfMinute());

        $settings = app(SourcePollingSettings::class);
        $settings->update(Source::TYPE_NEWS, true, 4, 'minutes');
        $settings->update(Source::TYPE_AIR_ALERT, false, 1, 'minutes');

        $api = TelegramApi::query()->create([
            'name' => 'Polling test API',
            'api_id' => 123456,
            'api_hash' => 'test-hash',
        ]);

        $account = TechnicalAccount::query()->create([
            'telegram_api_id' => $api->id,
            'name' => 'Polling test account',
            'is_active' => true,
        ]);

        $source = Source::query()->create([
            'technical_account_id' => $account->id,
            'type' => Source::TYPE_NEWS,
            'name' => 'Eight minute source',
            'source_peer' => '@eight_minute_source',
            'is_active' => true,
            'check_interval' => 8,
            'check_interval_unit' => 'minutes',
            'next_check_at' => now()->addMinutes(8),
        ]);

        $event = $this->sourceProcessingEvent(Source::TYPE_NEWS);
        $scheduler = app(SourceScheduler::class);

        $settings->markRun(Source::TYPE_NEWS);

        $this->travel(4)->minutes();
        $this->assertTrue($event->filtersPass($this->app));
        $this->assertFalse($scheduler->due(40, Source::TYPE_NEWS)->contains('id', $source->id));

        $settings->markRun(Source::TYPE_NEWS);

        $this->travel(4)->minutes();
        $this->assertTrue($event->filtersPass($this->app));
        $this->assertTrue($scheduler->due(40, Source::TYPE_NEWS)->contains('id', $source->id));
    }

    private function sourceProcessingEvent(string $type): object
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) $event->command,
                'skyguardian:sources:process --type='.$type,
            ));

        $this->assertNotNull($event);

        return $event;
    }
}
