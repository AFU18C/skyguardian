<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_sources', function (Blueprint $table): void {
            $table->unsignedInteger('poll_interval_seconds')->default(3)->after('text_processing_enabled');
            $table->timestamp('last_polled_at')->nullable()->after('last_published_at');
        });
    }

    public function down(): void
    {
        Schema::table('alert_sources', function (Blueprint $table): void {
            $table->dropColumn(['poll_interval_seconds', 'last_polled_at']);
        });
    }
};