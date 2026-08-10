<?php

namespace App\Console\Commands;

use App\Models\BetSearchRun;
use App\Services\BetSearchService;
use Illuminate\Console\Command;
use Throwable;

class ProcessBetSearchRuns extends Command
{
    protected $signature = 'skyguardian:betting-searches:process {--limit=1}';

    protected $description = 'Выполняет поставленные администратором поиски ставок';

    public function handle(BetSearchService $service): int
    {
        $limit = max(1, min(3, (int) $this->option('limit')));
        $failed = 0;

        BetSearchRun::query()
            ->where(function ($query): void {
                $query->where('status', BetSearchRun::STATUS_PENDING)
                    ->orWhere(function ($query): void {
                        $query->where('status', BetSearchRun::STATUS_RUNNING)
                            ->where('started_at', '<=', now()->subMinutes(30));
                    });
            })
            ->oldest('created_at')
            ->limit($limit)
            ->get()
            ->each(function (BetSearchRun $run) use ($service, &$failed): void {
                try {
                    $service->runQueued($run);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error('Bet search #'.$run->id.': '.$e->getMessage());
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
