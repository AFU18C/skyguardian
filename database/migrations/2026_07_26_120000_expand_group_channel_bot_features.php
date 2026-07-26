<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_bots', function (Blueprint $table): void {
            $table->string('token_fingerprint', 64)->nullable()->index()->after('bot_token');
            $table->string('webhook_secret', 64)->nullable()->after('token_fingerprint');
            $table->timestamp('webhook_registered_at')->nullable()->after('webhook_secret');
            $table->text('webhook_last_error')->nullable()->after('webhook_registered_at');
            $table->timestamp('last_update_at')->nullable()->after('webhook_last_error');
        });

        Schema::table('group_channel_publications', function (Blueprint $table): void {
            $table->string('type', 32)->default('text')->after('group_channel_bot_id');
            $table->json('media_paths')->nullable()->after('text');
            $table->json('buttons')->nullable()->after('media_paths');
            $table->json('reactions')->nullable()->after('buttons');
            $table->json('poll')->nullable()->after('reactions');
            $table->boolean('disable_notification')->default(false)->after('poll');
            $table->json('telegram_message_ids')->nullable()->after('telegram_message_id');
        });

        Schema::create('group_channel_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_channel_bot_id')->constrained()->cascadeOnDelete();
            $table->string('telegram_message_id', 64);
            $table->string('telegram_user_id', 64)->nullable()->index();
            $table->string('username')->nullable();
            $table->text('text')->nullable();
            $table->boolean('has_link')->default(false)->index();
            $table->string('matched_rule')->nullable()->index();
            $table->timestamp('telegram_created_at')->nullable()->index();
            $table->timestamp('deleted_at_telegram')->nullable();
            $table->timestamps();
            $table->unique(['group_channel_bot_id', 'telegram_message_id'], 'group_channel_message_unique');
        });

        Schema::create('group_channel_user_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_channel_bot_id')->constrained()->cascadeOnDelete();
            $table->string('telegram_user_id', 64);
            $table->unsignedInteger('warnings')->default(0);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_answer')->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('window_started_at')->nullable();
            $table->unsignedInteger('window_message_count')->default(0);
            $table->string('last_text_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['group_channel_bot_id', 'telegram_user_id'], 'group_channel_user_state_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_user_states');
        Schema::dropIfExists('group_channel_messages');

        Schema::table('group_channel_publications', function (Blueprint $table): void {
            $table->dropColumn([
                'type',
                'media_paths',
                'buttons',
                'reactions',
                'poll',
                'disable_notification',
                'telegram_message_ids',
            ]);
        });

        Schema::table('group_channel_bots', function (Blueprint $table): void {
            $table->dropColumn([
                'token_fingerprint',
                'webhook_secret',
                'webhook_registered_at',
                'webhook_last_error',
                'last_update_at',
            ]);
        });
    }
};
