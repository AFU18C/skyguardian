<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\SourceService;
use Illuminate\Console\Command;
use Throwable;

class CheckSource extends Command
{
    protected $signature = 'skyguardian:source:check {source : ID источника}';

    protected $description = 'Выполнить ручную проверку доступности Telegram-источника';

    public function handle(SourceService $service): int
    {
        $source = Source::query()->with('technicalAccount.telegramApi')->findOrFail((int) $this->argument('source'));

        try {
            $service->manualCheck($source);
            $this->info('Источник доступен.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
