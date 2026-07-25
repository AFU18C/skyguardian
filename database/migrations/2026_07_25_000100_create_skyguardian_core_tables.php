<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_apis', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('api_id');
            $table->text('api_hash');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('api_id');
        });

        Schema::create('technical_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_api_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('auth_method', 20)->default('phone');
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('telegram_user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->longText('session')->nullable();
            $table->text('auth_data')->nullable();
            $table->timestamp('auth_expires_at')->nullable();
            $table->string('status', 32)->default('not_checked');
            $table->text('last_error')->nullable();
            $table->timestamp('last_manual_check_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['status', 'is_active']);
            $table->index('telegram_user_id');
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technical_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('name');
            $table->string('source_peer');
            $table->string('destination_peer')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('check_interval')->default(60);
            $table->string('check_interval_unit', 16)->default('seconds');
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->string('status', 32)->default('not_checked');
            $table->text('last_error')->nullable();
            $table->timestamp('last_manual_check_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'is_active']);
            $table->index(['is_active', 'next_check_at']);
        });

        Schema::create('source_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();
            $table->unique(['source_id', 'key']);
        });

        Schema::create('operation_locks', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('technical_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('operation', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('expires_at');
            $table->index(['technical_account_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_locks');
        Schema::dropIfExists('source_rules');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('technical_accounts');
        Schema::dropIfExists('telegram_apis');
    }
};
