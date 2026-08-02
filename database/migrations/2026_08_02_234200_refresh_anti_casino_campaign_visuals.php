<?php

use App\Services\AntiCasinoTargetRotationService;
use App\Services\DiverseAntiCasinoVisualService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        app(DiverseAntiCasinoVisualService::class)->refreshScheduled();
        app(AntiCasinoTargetRotationService::class)->refreshScheduled();
    }

    public function down(): void
    {
        // Уже опубликованные изображения и подписи не откатываются автоматически.
    }
};
