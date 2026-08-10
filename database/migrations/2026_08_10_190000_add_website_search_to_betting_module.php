<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('betting_settings', function (Blueprint $table) {
            $table->json('website_sources')->nullable()->after('telegram_channels');
        });

        Schema::table('bets', function (Blueprint $table) {
            $table->json('search_sources')->nullable()->after('telegram_sources');
        });

        Schema::table('bet_search_runs', function (Blueprint $table) {
            $table->string('search_mode', 20)->default('telegram')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bet_search_runs', function (Blueprint $table) {
            $table->dropColumn('search_mode');
        });

        Schema::table('bets', function (Blueprint $table) {
            $table->dropColumn('search_sources');
        });

        Schema::table('betting_settings', function (Blueprint $table) {
            $table->dropColumn('website_sources');
        });
    }
};
