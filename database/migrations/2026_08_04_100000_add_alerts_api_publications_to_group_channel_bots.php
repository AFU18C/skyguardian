<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_bots', function (Blueprint $table): void {
            $table->text('alerts_api_token')->nullable()->after('bot_token');
            $table->char('alerts_api_token_fingerprint', 64)->nullable()->index()->after('alerts_api_token');
            $table->timestamp('alerts_api_initialized_at')->nullable()->after('last_test_message_error');
            $table->timestamp('alerts_api_last_checked_at')->nullable()->after('alerts_api_initialized_at');
            $table->timestamp('alerts_api_last_success_at')->nullable()->after('alerts_api_last_checked_at');
            $table->text('alerts_api_last_error')->nullable()->after('alerts_api_last_success_at');
        });

        Schema::create('group_channel_alert_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_channel_bot_id')->constrained()->cascadeOnDelete();
            $table->string('region_uid', 32);
            $table->string('region_name');
            $table->string('alert_type', 64);
            $table->unsignedBigInteger('source_alert_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['group_channel_bot_id', 'region_uid', 'alert_type'],
                'group_channel_alert_states_unique',
            );
        });

        Schema::create('group_channel_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_channel_bot_id')->constrained()->cascadeOnDelete();
            $table->char('event_key', 64);
            $table->string('kind', 16);
            $table->string('region_uid', 32);
            $table->string('region_name');
            $table->string('alert_type', 64);
            $table->timestamp('event_at');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sending_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['group_channel_bot_id', 'event_key'],
                'group_channel_alert_events_unique',
            );
            $table->index(
                ['group_channel_bot_id', 'status', 'event_at'],
                'group_channel_alert_events_queue',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_alert_events');
        Schema::dropIfExists('group_channel_alert_states');

        Schema::table('group_channel_bots', function (Blueprint $table): void {
            $table->dropIndex(['alerts_api_token_fingerprint']);
            $table->dropColumn([
                'alerts_api_token',
                'alerts_api_token_fingerprint',
                'alerts_api_initialized_at',
                'alerts_api_last_checked_at',
                'alerts_api_last_success_at',
                'alerts_api_last_error',
            ]);
        });
    }
};
