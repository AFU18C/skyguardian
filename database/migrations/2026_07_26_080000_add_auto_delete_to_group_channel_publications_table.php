<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_publications', function (Blueprint $table): void {
            $table->unsignedInteger('delete_after_minutes')->nullable()->after('scheduled_at');
            $table->timestamp('delete_at')->nullable()->after('sent_at');
            $table->timestamp('deleted_at_telegram')->nullable()->after('delete_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_channel_publications', function (Blueprint $table): void {
            $table->dropColumn([
                'delete_after_minutes',
                'delete_at',
                'deleted_at_telegram',
            ]);
        });
    }
};
