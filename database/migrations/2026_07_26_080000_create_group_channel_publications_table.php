<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_channel_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_channel_bot_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('status', 32)->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->string('telegram_message_id', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_publications');
    }
};
