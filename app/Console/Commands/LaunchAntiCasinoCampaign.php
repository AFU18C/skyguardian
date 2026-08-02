<?php

namespace App\Console\Commands;

use App\Services\AntiCasinoCampaignService;
use Illuminate\Console\Command;
use Throwable;

class LaunchAntiCasinoCampaign extends Command
{
    protected $signature = 'skyguardian:anti-casino-campaign:launch';

    protected $description = 'Создаёт и планирует 1000 фото-публикаций против рекламы казино';

    public function handle(AntiCasinoCampaignService $campaign): int
    {
        try {
            $created = $campaign->launch();
            $this->info('Антиказино-кампания готова. Создано публикаций: '.$created);

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
