<?php

namespace App\Jobs;

use App\Models\BetSearchRun;
use App\Models\BettingSetting;
use App\Services\BetSearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunBetSearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('betting');
    }

    public function handle(BetSearchService $service): void
    {
        $run = BetSearchRun::query()->findOrFail($this->runId);
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }

        $service->run(BettingSetting::current(), $run->search_mode, $run);
    }

    public function failed(?Throwable $exception): void
    {
        BetSearchRun::query()->whereKey($this->runId)->update([
            'status' => 'error',
            'status_message' => 'Фоновая задача завершилась с ошибкой',
            'last_error' => mb_substr($exception?->getMessage() ?? 'Неизвестная ошибка фоновой задачи.', 0, 2000),
            'finished_at' => now(),
        ]);
    }
}
