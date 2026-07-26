<?php

namespace App\Console\Commands;

use App\Models\GroupChannelPublication;
use App\Services\GroupChannelPublicationService;
use Illuminate\Console\Command;
use Throwable;

class ProcessGroupChannelPublications extends Command
{
    protected $signature = 'skyguardian:group-channel-publications:process {--limit=20}';

    protected $description = 'Отправляет и удаляет публикации Bot API по расписанию';

    public function handle(GroupChannelPublicationService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));

        GroupChannelPublication::query()
            ->where('status', GroupChannelPublication::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->oldest('scheduled_at')
            ->limit($limit)
            ->get()
            ->each(function (GroupChannelPublication $publication) use ($service): void {
                try {
                    $service->send($publication);
                } catch (Throwable $e) {
                    report($e);
                    $this->error('Публикация #'.$publication->id.': '.$e->getMessage());
                }
            });

        GroupChannelPublication::query()
            ->where('status', GroupChannelPublication::STATUS_SENT)
            ->whereNotNull('delete_at')
            ->whereNull('deleted_at_telegram')
            ->where('delete_at', '<=', now())
            ->oldest('delete_at')
            ->limit($limit)
            ->get()
            ->each(function (GroupChannelPublication $publication) use ($service): void {
                try {
                    $service->delete($publication);
                } catch (Throwable $e) {
                    report($e);
                    $this->error('Удаление публикации #'.$publication->id.': '.$e->getMessage());
                }
            });

        return self::SUCCESS;
    }
}
