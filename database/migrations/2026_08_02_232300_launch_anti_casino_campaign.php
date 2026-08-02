<?php

use App\Services\AntiCasinoCampaignService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        app(AntiCasinoCampaignService::class)->launch();
    }

    public function down(): void
    {
        // Уже опубликованные и запланированные посты автоматически не удаляются.
    }
};
