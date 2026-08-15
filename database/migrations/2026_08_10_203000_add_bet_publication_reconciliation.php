<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table): void {
            $table->text('publication_error')->nullable()->after('publication_text');
            $table->string('result_publication_status', 24)->nullable()->index()->after('result_sent_at');
            $table->text('result_publication_error')->nullable()->after('result_publication_status');
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table): void {
            $table->dropColumn(['publication_error', 'result_publication_status', 'result_publication_error']);
        });
    }
};
