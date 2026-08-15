<?php

namespace App\Console\Commands;

use App\Models\GroupChannelMessage;
use App\Models\GroupChannelPublication;
use App\Services\GroupChannelPublicationService;
use App\Services\GroupChannelTelegramService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ProcessGroupChannelPublications extends Command
{
    protected $signature = 'skyguardian:group-channel-publications:process {--limit=20}';

    protected $description = 'Отправляет и удаляет публикации Bot API по расписанию';

    public function handle(
        GroupChannelPublicationService $service,
        GroupChannelTelegramService $telegram,
    ): int {
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
            ->whereNull('delete_failed_at')
            ->where(function ($query): void {
                $query->whereNull('next_delete_attempt_at')->orWhere('next_delete_attempt_at', '<=', now());
            })
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

        GroupChannelMessage::query()
            ->with('bot')
            ->whereNotNull('delete_at')
            ->whereNull('deleted_at_telegram')
            ->whereNull('delete_failed_at')
            ->where(function ($query): void {
                $query->whereNull('next_delete_attempt_at')->orWhere('next_delete_attempt_at', '<=', now());
            })
            ->where('delete_at', '<=', now())
            ->oldest('delete_at')
            ->limit($limit)
            ->get()
            ->each(function (GroupChannelMessage $message) use ($telegram): void {
                try {
                    if (! $message->bot?->is_active || ! $message->bot->chat_id) {
                        throw new RuntimeException('Бот отключён или Chat ID не определён.');
                    }

                    $telegram->request($message->bot, 'deleteMessage', [
                        'chat_id' => $message->bot->chat_id,
                        'message_id' => $message->telegram_message_id,
                    ]);
                    $message->update([
                        'deleted_at_telegram' => now(),
                        'deletion_attempts' => 0,
                        'next_delete_attempt_at' => null,
                        'delete_failed_at' => null,
                    ]);
                } catch (Throwable $e) {
                    report($e);
                    if ($this->alreadyDeleted($e)) {
                        $message->update([
                            'deleted_at_telegram' => now(),
                            'deletion_attempts' => 0,
                            'next_delete_attempt_at' => null,
                            'delete_failed_at' => null,
                        ]);

                        return;
                    }
                    $attempts = $message->deletion_attempts + 1;
                    $message->update([
                        'deletion_attempts' => $attempts,
                        'next_delete_attempt_at' => $attempts >= 10 ? null : now()->addSeconds(min(3600, 15 * (2 ** max(0, $attempts - 1)))),
                        'delete_failed_at' => $attempts >= 10 ? now() : null,
                    ]);
                    $this->error('Удаление сообщения #'.$message->id.': '.$e->getMessage());
                }
            });

        return self::SUCCESS;
    }

    private function alreadyDeleted(Throwable $error): bool
    {
        $message = mb_strtolower($error->getMessage());

        return str_contains($message, 'message to delete not found')
            || str_contains($message, 'message_id_invalid');
    }
}
