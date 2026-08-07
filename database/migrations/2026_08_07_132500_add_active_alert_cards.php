<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_alert_states', function (Blueprint $table): void {
            $table->string('scope_region_uid', 32)->nullable()->after('region_uid')->index();
        });

        Schema::table('group_channel_alert_events', function (Blueprint $table): void {
            $table->string('scope_region_uid', 32)->nullable()->after('region_uid')->index();
        });

        Schema::create('group_channel_alert_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_channel_bot_id')->constrained()->cascadeOnDelete();
            $table->string('scope_region_uid', 32);
            $table->string('alert_type', 64);
            $table->string('snapshot_hash', 64);
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['group_channel_bot_id', 'scope_region_uid', 'alert_type'],
                'group_channel_alert_cards_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_alert_cards');

        Schema::table('group_channel_alert_events', function (Blueprint $table): void {
            $table->dropIndex(['scope_region_uid']);
            $table->dropColumn('scope_region_uid');
        });

        Schema::table('group_channel_alert_states', function (Blueprint $table): void {
            $table->dropIndex(['scope_region_uid']);
            $table->dropColumn('scope_region_uid');
        });
    }
};
