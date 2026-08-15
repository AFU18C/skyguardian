<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_publications', function (Blueprint $table): void {
            $table->unsignedSmallInteger('deletion_attempts')->default(0)->after('deleted_at_telegram');
            $table->timestamp('next_delete_attempt_at')->nullable()->index()->after('deletion_attempts');
            $table->timestamp('delete_failed_at')->nullable()->index()->after('next_delete_attempt_at');
        });

        Schema::table('group_channel_messages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('deletion_attempts')->default(0)->after('deleted_at_telegram');
            $table->timestamp('next_delete_attempt_at')->nullable()->index()->after('deletion_attempts');
            $table->timestamp('delete_failed_at')->nullable()->index()->after('next_delete_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_channel_publications', function (Blueprint $table): void {
            $table->dropColumn(['deletion_attempts', 'next_delete_attempt_at', 'delete_failed_at']);
        });
        Schema::table('group_channel_messages', function (Blueprint $table): void {
            $table->dropColumn(['deletion_attempts', 'next_delete_attempt_at', 'delete_failed_at']);
        });
    }
};
