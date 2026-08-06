<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Services\SourcePollingSettings;
use App\Services\SourceProcessor;
use App\Services\SourceScheduler;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class ProcessSourcesCommandTest extends TestCase
{
    public function test_command_uses_messages_copied_result_and_finishes_successfully(): void
    {
        $source = new Source;
        $source->id = 42;

        $scheduler = Mockery::mock(SourceScheduler::class);
        $scheduler->shouldReceive('due')
            ->once()
            ->with(40, Source::TYPE_NEWS)
            ->andReturn(new Collection([$source]));
        $scheduler->shouldReceive('due')
            ->once()
            ->with(40, Source::TYPE_AIR_ALERT)
            ->andReturn(collect());

        $processor = Mockery::mock(SourceProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with($source)
            ->andReturn([
                'messages_found' => 3,
                'messages_copied' => 2,
            ]);

        $polling = Mockery::mock(SourcePollingSettings::class);
        $polling->shouldReceive('shouldRun')->twice()->andReturnTrue();
        $polling->shouldReceive('markRun')->twice();

        $this->app->instance(SourceScheduler::class, $scheduler);
        $this->app->instance(SourceProcessor::class, $processor);
        $this->app->instance(SourcePollingSettings::class, $polling);

        $this->artisan('skyguardian:sources:process')
            ->expectsOutput('Источник 42: найдено 3, переслано 2.')
            ->assertSuccessful();
    }

    public function test_command_handles_missing_optional_counters_without_runtime_error(): void
    {
        $source = new Source;
        $source->id = 7;

        $scheduler = Mockery::mock(SourceScheduler::class);
        $scheduler->shouldReceive('due')
            ->once()
            ->with(40, Source::TYPE_NEWS)
            ->andReturn(new Collection([$source]));
        $scheduler->shouldReceive('due')
            ->once()
            ->with(40, Source::TYPE_AIR_ALERT)
            ->andReturn(collect());

        $processor = Mockery::mock(SourceProcessor::class);
        $processor->shouldReceive('process')->once()->andReturn([]);

        $polling = Mockery::mock(SourcePollingSettings::class);
        $polling->shouldReceive('shouldRun')->twice()->andReturnTrue();
        $polling->shouldReceive('markRun')->twice();

        $this->app->instance(SourceScheduler::class, $scheduler);
        $this->app->instance(SourceProcessor::class, $processor);
        $this->app->instance(SourcePollingSettings::class, $polling);

        $this->artisan('skyguardian:sources:process')
            ->expectsOutput('Источник 7: найдено 0, переслано 0.')
            ->assertSuccessful();
    }
}
