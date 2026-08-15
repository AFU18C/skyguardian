<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('betting_settings', function (Blueprint $table): void {
            $table->string('primary_source_url')->nullable()->change();
        });

        Schema::table('bet_search_runs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('search_mode');
            $table->string('status_message')->nullable()->after('progress_percent');
            $table->timestamp('started_at')->nullable()->after('status_message');
            $table->index(['status', 'created_at'], 'bet_search_runs_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('bet_search_runs', function (Blueprint $table): void {
            $table->dropIndex('bet_search_runs_status_created_index');
            $table->dropColumn(['progress_percent', 'status_message', 'started_at']);
        });

        Schema::table('betting_settings', function (Blueprint $table): void {
            $table->string('primary_source_url')->nullable(false)->default('https://beton.ua/sportsbook')->change();
        });
    }
};
