<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_channel_webhook_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_channel_bot_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_update_id');
            $table->json('payload');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['group_channel_bot_id', 'telegram_update_id'],
                'group_channel_webhook_update_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_webhook_updates');
    }
};
