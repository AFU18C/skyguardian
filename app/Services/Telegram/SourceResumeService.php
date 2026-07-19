<?php

namespace App\Services\Telegram;

use App\Models\AlertSource;
use App\Models\NewsSource;
use Illuminate\Support\Collection;
use RuntimeException;

class SourceResumeService
{
    public function __construct(
        private readonly TelethonAccountService $telethon,
    ) {
    }

    public function checkpoint(AlertSource|NewsSource $source): int
    {
        $latestId = $this->latestId($source);
        $this->saveCheckpoint($source, $latestId);

        return $latestId;
    }

    /**
     * @param Collection<int, AlertSource|NewsSource> $sources
     */
    public function checkpointMany(Collection $sources): void
    {
        $checkpoints = [];

        foreach ($sources as $source) {
            $checkpoints[] = [$source, $this->latestId($source)];
        }

        foreach ($checkpoints as [$source, $latestId]) {
            $this->saveCheckpoint($source, $latestId);
        }
    }

    private function latestId(AlertSource|NewsSource $source): int
    {
        $source->loadMissing('readerAccount.telegramApiCredential');
        $reader = $source->readerAccount;

        if (! $reader || ! $reader->telegramApiCredential) {
            throw new RuntimeException('Для источника не настроен технический аккаунт чтения и Telegram API.');
        }

        return $this->telethon->latestMessageId($reader, (string) $source->source_chat);
    }

    private function saveCheckpoint(AlertSource|NewsSource $source, int $latestId): void
    {
        $source->forceFill([
            'last_source_message_id' => $latestId > 0 ? $latestId : null,
            'last_polled_at' => now(),
        ])->save();
    }
}
