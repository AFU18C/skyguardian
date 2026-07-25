<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_channel_bots', function (Blueprint $table): void {
            $table->id();
            $table->string('bot_name');
            $table->text('bot_token');
            $table->string('admin_id');
            $table->string('group_name');
            $table->string('group_link');
            $table->string('chat_type')->default('group');
            $table->string('chat_id')->nullable()->index();
            $table->string('bot_username')->nullable();
            $table->string('status')->default('not_checked')->index();
            $table->json('permissions')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_manual_check_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['bot_name', 'group_link']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_bots');
    }
};