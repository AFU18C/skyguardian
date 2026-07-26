<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_bots', function (Blueprint $table): void {
            $table->json('module_settings')->nullable()->after('permissions');
            $table->timestamp('last_test_message_at')->nullable()->after('last_manual_check_at');
            $table->text('last_test_message_error')->nullable()->after('last_test_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_channel_bots', function (Blueprint $table): void {
            $table->dropColumn([
                'module_settings',
                'last_test_message_at',
                'last_test_message_error',
            ]);
        });
    }
};
