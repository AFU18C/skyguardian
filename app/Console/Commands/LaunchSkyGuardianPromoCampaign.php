<?php

namespace App\Console\Commands;

use App\Services\SkyGuardianPromoCampaignService;
use Illuminate\Console\Command;
use Throwable;

class LaunchSkyGuardianPromoCampaign extends Command
{
    protected $signature = 'skyguardian:promo-campaign:launch';

    protected $description = 'Создаёт и планирует 10 фото-публикаций промо-кампании SkyGuardian';

    public function handle(SkyGuardianPromoCampaignService $campaign): int
    {
        try {
            $created = $campaign->launch();
            $this->info('Промо-кампания готова. Создано публикаций: '.$created);

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
