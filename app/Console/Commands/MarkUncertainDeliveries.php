<?php

namespace App\Console\Commands;

use App\Models\Bet;
use App\Models\GroupChannelAlertCard;
use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelPublication;
use Illuminate\Console\Command;

class MarkUncertainDeliveries extends Command
{
    protected $signature = 'skyguardian:deliveries:mark-uncertain';

    protected $description = 'Quarantine delivery attempts that ended without a Telegram acknowledgement';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(10);

        $publications = GroupChannelPublication::query()
            ->where('status', GroupChannelPublication::STATUS_SENDING)
            ->where('sending_started_at', '<=', $cutoff)
            ->update([
                'status' => GroupChannelPublication::STATUS_UNCERTAIN,
                'sending_started_at' => null,
                'last_error' => 'Процесс завершился без подтверждения Telegram. Проверьте канал перед повтором.',
                'updated_at' => now(),
            ]);

        $bets = Bet::query()
            ->where('status', Bet::STATUS_PUBLISHING)
            ->where('updated_at', '<=', $cutoff)
            ->update([
                'status' => Bet::STATUS_PUBLICATION_UNCERTAIN,
                'publication_error' => 'Процесс завершился без подтверждения Telegram. Проверьте канал перед повтором.',
                'updated_at' => now(),
            ]);

        $results = Bet::query()
            ->where('result_publication_status', Bet::RESULT_PUBLICATION_SENDING)
            ->where('updated_at', '<=', $cutoff)
            ->update([
                'result_publication_status' => Bet::RESULT_PUBLICATION_UNCERTAIN,
                'result_publication_error' => 'Процесс завершился без подтверждения Telegram. Проверьте канал перед повтором.',
                'updated_at' => now(),
            ]);

        $alertEvents = GroupChannelAlertEvent::query()
            ->where('status', GroupChannelAlertEvent::STATUS_SENDING)
            ->where('sending_started_at', '<=', $cutoff)
            ->update([
                'status' => GroupChannelAlertEvent::STATUS_UNCERTAIN,
                'sending_started_at' => null,
                'last_error' => 'Процесс завершился без подтверждения Telegram. Проверьте канал перед повтором.',
                'updated_at' => now(),
            ]);

        $alertCards = GroupChannelAlertCard::query()
            ->where('delivery_status', GroupChannelAlertCard::STATUS_SENDING)
            ->where('sending_started_at', '<=', $cutoff)
            ->update([
                'delivery_status' => GroupChannelAlertCard::STATUS_UNCERTAIN,
                'sending_started_at' => null,
                'last_error' => 'Процесс завершился без подтверждения Telegram. Проверьте канал перед повтором.',
                'updated_at' => now(),
            ]);

        if ($publications + $bets + $results + $alertEvents + $alertCards > 0) {
            $this->warn(
                "Quarantined {$publications} publications, {$bets} bets, {$results} results, "
                ."{$alertEvents} alert events and {$alertCards} alert cards.",
            );
        }

        return self::SUCCESS;
    }
}
