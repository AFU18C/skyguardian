<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_bot_settings', function (Blueprint $table): void {
            $table->id();
            $table->text('bot_token')->nullable();
            $table->string('administrator_telegram_id', 32)->nullable();
            $table->string('service_status', 32)->default('stopped');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_bot_settings');
    }
};
