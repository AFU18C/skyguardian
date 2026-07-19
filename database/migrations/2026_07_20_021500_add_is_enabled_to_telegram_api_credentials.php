<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_api_credentials', function (Blueprint $table): void {
            $table->boolean('is_enabled')->default(true);
        });

        Schema::table('news_telegram_api_credentials', function (Blueprint $table): void {
            $table->boolean('is_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_api_credentials', function (Blueprint $table): void {
            $table->dropColumn('is_enabled');
        });

        Schema::table('news_telegram_api_credentials', function (Blueprint $table): void {
            $table->dropColumn('is_enabled');
        });
    }
};
