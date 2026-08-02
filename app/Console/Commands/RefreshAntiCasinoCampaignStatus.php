<?php

namespace App\Console\Commands;

use App\Services\AntiCasinoCampaignService;
use Illuminate\Console\Command;

class RefreshAntiCasinoCampaignStatus extends Command
{
    protected $signature = 'skyguardian:anti-casino-campaign:status';

    protected $description = 'Обновляет публичный JSON-статус антиказино-кампании';

    public function handle(AntiCasinoCampaignService $campaign): int
    {
        $campaign->writeStatus();

        return self::SUCCESS;
    }
}
