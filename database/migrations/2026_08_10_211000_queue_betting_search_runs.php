<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bet_search_runs', function (Blueprint $table) {
            $table->foreignId('requested_by_user_id')->nullable()->after('search_mode')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('attempts')->default(0)->after('requested_by_user_id');
            $table->timestamp('started_at')->nullable()->after('last_error');
            $table->index(['status', 'created_at'], 'bet_search_runs_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bet_search_runs', function (Blueprint $table) {
            $table->dropIndex('bet_search_runs_queue_idx');
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn(['attempts', 'started_at']);
        });
    }
};
