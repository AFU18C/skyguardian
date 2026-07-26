<?php

namespace App\Console\Commands;

use App\Models\GroupChannelPublication;
use App\Services\GroupChannelPublicationService;
use Illuminate\Console\Command;
use Throwable;

class ProcessGroupChannelPublications extends Command
{
    protected $signature = 'skyguardian:group-channel-publications:process {--limit=20}';

    protected $description = 'Отправляет запланированные публикации Bot API';

    public function handle(GroupChannelPublicationService $service): int
    {
        GroupChannelPublication::query()
            ->where('status', GroupChannelPublication::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->oldest('scheduled_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (GroupChannelPublication $publication) use ($service): void {
                try {
                    $service->send($publication);
                } catch (Throwable $e) {
                    report($e);
                    $this->error('Публикация #'.$publication->id.': '.$e->getMessage());
                }
            });

        return self::SUCCESS;
    }
}
