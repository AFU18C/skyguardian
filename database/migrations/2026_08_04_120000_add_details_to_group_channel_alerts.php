<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_alert_states', function (Blueprint $table): void {
            $table->text('details')->nullable()->after('alert_type');
        });

        Schema::table('group_channel_alert_events', function (Blueprint $table): void {
            $table->text('details')->nullable()->after('alert_type');
        });
    }

    public function down(): void
    {
        Schema::table('group_channel_alert_events', function (Blueprint $table): void {
            $table->dropColumn('details');
        });

        Schema::table('group_channel_alert_states', function (Blueprint $table): void {
            $table->dropColumn('details');
        });
    }
};
