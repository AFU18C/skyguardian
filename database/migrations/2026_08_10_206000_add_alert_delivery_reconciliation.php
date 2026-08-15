<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_alert_events', function (Blueprint $table): void {
            $table->uuid('delivery_batch_id')->nullable()->after('sending_started_at')->index();
            $table->unsignedBigInteger('telegram_message_id')->nullable()->after('delivery_batch_id');
        });

        Schema::table('group_channel_alert_cards', function (Blueprint $table): void {
            $table->char('pending_snapshot_hash', 64)->nullable()->after('snapshot_hash');
            $table->unsignedBigInteger('pending_telegram_message_id')->nullable()->after('telegram_message_id');
            $table->string('delivery_status', 16)->default('sent')->after('pending_telegram_message_id')->index();
            $table->timestamp('sending_started_at')->nullable()->after('delivery_status');
            $table->text('last_error')->nullable()->after('sending_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_channel_alert_cards', function (Blueprint $table): void {
            $table->dropIndex(['delivery_status']);
            $table->dropColumn([
                'pending_snapshot_hash',
                'pending_telegram_message_id',
                'delivery_status',
                'sending_started_at',
                'last_error',
            ]);
        });

        Schema::table('group_channel_alert_events', function (Blueprint $table): void {
            $table->dropIndex(['delivery_batch_id']);
            $table->dropColumn([
                'delivery_batch_id',
                'telegram_message_id',
            ]);
        });
    }
};
