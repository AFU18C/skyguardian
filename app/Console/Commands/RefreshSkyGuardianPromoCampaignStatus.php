<?php

namespace App\Console\Commands;

use App\Services\SkyGuardianPromoCampaignService;
use Illuminate\Console\Command;

class RefreshSkyGuardianPromoCampaignStatus extends Command
{
    protected $signature = 'skyguardian:promo-campaign:status';

    protected $description = 'Обновляет публичный JSON-статус промо-кампании SkyGuardian';

    public function handle(SkyGuardianPromoCampaignService $campaign): int
    {
        $campaign->writeStatus();

        return self::SUCCESS;
    }
}
