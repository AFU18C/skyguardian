<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_bot_settings', function (Blueprint $table): void {
            $table->text('telegram_api_id')->nullable()->after('technical_status');
            $table->text('telegram_api_hash')->nullable()->after('telegram_api_id');
        });
    }

    public function down(): void
    {
        Schema::table('alert_bot_settings', function (Blueprint $table): void {
            $table->dropColumn(['telegram_api_id', 'telegram_api_hash']);
        });
    }
};
