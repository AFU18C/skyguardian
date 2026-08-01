<?php

namespace App\Console\Commands;

use App\Services\SourceProcessor;
use App\Services\SourceScheduler;
use Illuminate\Console\Command;
use Throwable;

class ProcessSources extends Command
{
    protected $signature = 'skyguardian:sources:process {--limit=40 : Максимальное количество источников за запуск}';

    protected $description = 'Обработать Telegram-источники, для которых наступило время проверки';

    public function handle(SourceScheduler $scheduler, SourceProcessor $processor): int
    {
        $limit = max(1, min((int) $this->option('limit'), 40));
        $sources = $scheduler->due($limit);
        $failed = 0;

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

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
