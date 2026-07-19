<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 100);
            $table->string('source_chat');
            $table->string('destination_chat');
            $table->foreignId('reader_account_id')->constrained('technical_telegram_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('publisher_account_id')->constrained('technical_telegram_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('autopublish_enabled')->default(false);
            $table->boolean('text_processing_enabled')->default(true);
            $table->unsignedInteger('poll_interval_seconds')->default(3);
            $table->string('source_status', 32)->default('not_checked');
            $table->string('destination_status', 32)->default('not_checked');
            $table->string('destination_type', 32)->nullable();
            $table->string('publish_as', 32)->nullable();
            $table->bigInteger('last_source_message_id')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_sources');
    }
};
