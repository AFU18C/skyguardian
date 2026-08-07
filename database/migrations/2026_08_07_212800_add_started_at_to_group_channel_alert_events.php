<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_alert_events', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('event_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_channel_alert_events', function (Blueprint $table): void {
            $table->dropColumn('started_at');
        });
    }
};
