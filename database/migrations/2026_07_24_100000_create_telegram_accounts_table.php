<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('api_id');
            $table->text('api_hash');
            $table->string('login_method', 20)->default('phone');
            $table->string('phone')->nullable();
            $table->string('telegram_name')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('status', 30)->default('not_connected');
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_accounts');
    }
};
