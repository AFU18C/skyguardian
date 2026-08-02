<?php

namespace App\Console\Commands;

use App\Services\AntiCasinoTargetRotationService;
use App\Services\DiverseAntiCasinoVisualService;
use Illuminate\Console\Command;
use Throwable;

class RefreshAntiCasinoCampaignVisuals extends Command
{
    protected $signature = 'skyguardian:anti-casino-campaign:refresh-visuals';

    protected $description = 'Обновляет очередь антиказино-кампании: 300 дизайнов и ротация каналов';

    public function handle(
        DiverseAntiCasinoVisualService $visuals,
        AntiCasinoTargetRotationService $targets,
    ): int {
        try {
            $images = $visuals->refreshScheduled();
            $captions = $targets->refreshScheduled();

            $this->info("Обновлено изображений: {$images}; подписей: {$captions}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
