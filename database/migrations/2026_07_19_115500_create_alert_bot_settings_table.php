<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_bot_settings', function (Blueprint $table): void {
            $table->id();
            $table->text('technical_phone')->nullable();
            $table->string('technical_name')->nullable();
            $table->string('technical_username')->nullable();
            $table->string('technical_telegram_id')->nullable();
            $table->string('technical_status')->default('disconnected');
            $table->text('bot_token')->nullable();
            $table->string('administrator_telegram_id')->nullable();
            $table->string('bot_status')->default('not_configured');
            $table->string('source_chat')->nullable();
            $table->string('source_status')->default('not_checked');
            $table->string('destination_chat')->nullable();
            $table->string('destination_status')->default('not_checked');
            $table->boolean('autopublish_enabled')->default(false);
            $table->boolean('text_processing_enabled')->default(true);
            $table->string('service_status')->default('stopped');
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('extra_settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_bot_settings');
    }
};
