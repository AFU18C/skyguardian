<?php

namespace App\Console\Commands;

use App\Models\GroupChannelTechnicalDeleteTask;
use App\Services\GroupChannelTelethonClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkGroupChannelTechnicalDeletes extends Command
{
    protected $signature = 'skyguardian:group-channel-technical-delete:work {--once}';

    protected $description = 'Обрабатывает задачи удаления истории через технические аккаунты';

    public function __construct(private readonly GroupChannelTelethonClient $telethon)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->recoverInterruptedTasks();

        do {
            $task = $this->claimTask();

            if (! $task) {
                if ($this->option('once')) {
                    return self::SUCCESS;
                }

                sleep(3);

                continue;
            }

            $this->processTask($task);
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function claimTask(): ?GroupChannelTechnicalDeleteTask
    {
        return DB::transaction(function (): ?GroupChannelTechnicalDeleteTask {
            $task = GroupChannelTechnicalDeleteTask::query()
                ->where('status', GroupChannelTechnicalDeleteTask::STATUS_PENDING)
                ->oldest('created_at')
                ->lockForUpdate()
                ->first();

            if (! $task) {
                return null;
            }

            $task->update([
                'status' => GroupChannelTechnicalDeleteTask::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'last_error' => null,
            ]);

            return $task->fresh(['bot', 'technicalAccount.telegramApi']);
        });
    }

    private function processTask(GroupChannelTechnicalDeleteTask $task): void
    {
        try {
            $bot = $task->bot;
            $account = $task->technicalAccount;

            if (! $bot) {
                throw new \RuntimeException('Группа или канал больше не существует.');
            }

            if (! $account) {
                throw new \RuntimeException('Выбранный технический аккаунт удалён из системы.');
            }

            $result = $this->telethon->call(
                'group_channel_bulk_delete',
                $account,
                [
                    'peer' => $bot->group_link,
                    ...($task->criteria ?? []),
                ],
                21600,
            );

            $task->update([
                'status' => GroupChannelTechnicalDeleteTask::STATUS_COMPLETED,
                'matched_count' => (int) ($result['matched_count'] ?? $task->matched_count),
                'deleted_count' => (int) ($result['deleted_count'] ?? 0),
                'failed_count' => (int) ($result['failed_count'] ?? 0),
                'finished_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);
            $task->update([
                'status' => GroupChannelTechnicalDeleteTask::STATUS_FAILED,
                'finished_at' => now(),
                'last_error' => $e->getMessage(),
            ]);
        }
    }

    private function recoverInterruptedTasks(): void
    {
        GroupChannelTechnicalDeleteTask::query()
            ->where('status', GroupChannelTechnicalDeleteTask::STATUS_RUNNING)
            ->update([
                'status' => GroupChannelTechnicalDeleteTask::STATUS_PENDING,
                'started_at' => null,
                'last_error' => 'Задача возвращена в очередь после перезапуска отдельного обработчика.',
            ]);
    }
}
