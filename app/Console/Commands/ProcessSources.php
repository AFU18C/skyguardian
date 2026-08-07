<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\SourcePollingSettings;
use App\Services\SourceProcessor;
use App\Services\SourceScheduler;
use Illuminate\Console\Command;
use Throwable;

class ProcessSources extends Command
{
    protected $signature = 'skyguardian:sources:process {--limit=40 : Максимальное количество источников за запуск}';

    protected $description = 'Обработать Telegram-источники, для которых наступило время проверки';

    public function handle(
        SourceScheduler $scheduler,
        SourceProcessor $processor,
        SourcePollingSettings $polling,
    ): int {
        $limit = max(1, min((int) $this->option('limit'), 40));
        $failed = 0;

        foreach ([Source::TYPE_NEWS, Source::TYPE_AIR_ALERT] as $type) {
            if (! $polling->shouldRun($type)) {
                continue;
            }

            try {
                $sources = $scheduler->due($limit, $type);

                foreach ($sources as $source) {
                    try {
                        $result = $processor->process($source);
                        $this->line(sprintf(
                            'Источник %d: найдено %d, переслано %d.',
                            $source->id,
                            (int) ($result['messages_found'] ?? 0),
                            (int) ($result['messages_copied'] ?? 0),
                        ));
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                        $this->error("Источник {$source->id}: {$e->getMessage()}");
                    }
                }
            } finally {
                $polling->markRun($type);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
