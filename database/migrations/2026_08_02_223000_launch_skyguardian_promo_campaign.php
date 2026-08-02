<?php

use App\Services\SkyGuardianPromoCampaignService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(SkyGuardianPromoCampaignService::class)->launch();
    }

    public function down(): void
    {
        // Публикации не удаляются автоматически при откате миграции.
    }
};
