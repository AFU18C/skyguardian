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
        $source->loadMissing('readerAccount.telegramApiCredential');
        $reader = $source->readerAccount;

        if (! $reader || ! $reader->telegramApiCredential) {
            throw new RuntimeException('Для источника не настроен технический аккаунт чтения и Telegram API.');
        }

        $latestId = $this->telethon->latestMessageId($reader, (string) $source->source_chat);

        $source->forceFill([
            'last_source_message_id' => $latestId > 0 ? $latestId : null,
            'last_polled_at' => now(),
        ])->save();

        return $latestId;
    }

    /**
     * @param Collection<int, AlertSource|NewsSource> $sources
     */
    public function checkpointMany(Collection $sources): void
    {
        foreach ($sources as $source) {
            $this->checkpoint($source);
        }
    }
}
