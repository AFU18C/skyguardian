<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('label')->nullable();
            $table->text('phone')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('telegram_id')->nullable();
            $table->string('status')->default('disconnected');
            $table->boolean('is_primary')->default(false)->index();
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_telegram_accounts');
    }
};
