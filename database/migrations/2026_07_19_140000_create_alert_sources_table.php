<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 100);
            $table->string('source_chat');
            $table->string('destination_chat');
            $table->foreignId('reader_account_id')->constrained('technical_telegram_accounts')->cascadeOnDelete();
            $table->foreignId('publisher_account_id')->constrained('technical_telegram_accounts')->cascadeOnDelete();
            $table->boolean('autopublish_enabled')->default(false);
            $table->boolean('text_processing_enabled')->default(true);
            $table->string('source_status', 32)->default('not_checked');
            $table->string('destination_status', 32)->default('not_checked');
            $table->string('destination_type', 32)->nullable();
            $table->string('publish_as', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_sources');
    }
};