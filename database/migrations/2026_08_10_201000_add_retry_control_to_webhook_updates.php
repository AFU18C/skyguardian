<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_webhook_updates', function (Blueprint $table): void {
            $table->timestamp('next_attempt_at')->nullable()->index()->after('attempts');
            $table->timestamp('dead_lettered_at')->nullable()->index()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_channel_webhook_updates', function (Blueprint $table): void {
            $table->dropColumn(['next_attempt_at', 'dead_lettered_at']);
        });
    }
};
