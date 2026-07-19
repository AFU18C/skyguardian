<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_bot_settings', function (Blueprint $table): void {
            $table->string('bot_name', 100)->nullable()->after('telegram_api_hash');
        });

        Schema::table('news_bot_settings', function (Blueprint $table): void {
            $table->string('bot_name', 100)->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('alert_bot_settings', function (Blueprint $table): void {
            $table->dropColumn('bot_name');
        });

        Schema::table('news_bot_settings', function (Blueprint $table): void {
            $table->dropColumn('bot_name');
        });
    }
};
