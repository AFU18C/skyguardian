<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->foreignId('telegram_account_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('peer_id')->nullable()->after('identifier');
            $table->unique(['telegram_account_id', 'peer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropUnique(['telegram_account_id', 'peer_id']);
            $table->dropConstrainedForeignId('telegram_account_id');
            $table->dropColumn('peer_id');
        });
    }
};