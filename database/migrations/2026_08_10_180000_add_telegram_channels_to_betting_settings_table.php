<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('betting_settings', function (Blueprint $table) {
            $table->json('telegram_channels')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('betting_settings', function (Blueprint $table) {
            $table->dropColumn('telegram_channels');
        });
    }
};
