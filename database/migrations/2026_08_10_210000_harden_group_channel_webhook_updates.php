<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_webhook_updates', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')->nullable()->after('last_error')->index();
            $table->timestamp('dead_letter_at')->nullable()->after('processed_at')->index();
            $table->index(['status', 'next_attempt_at'], 'gc_webhook_updates_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('group_channel_webhook_updates', function (Blueprint $table) {
            $table->dropIndex('gc_webhook_updates_due_idx');
            $table->dropIndex(['next_attempt_at']);
            $table->dropIndex(['dead_letter_at']);
            $table->dropColumn(['next_attempt_at', 'dead_letter_at']);
        });
    }
};
