<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_channel_technical_delete_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_channel_bot_id')
                ->constrained('group_channel_bots')
                ->cascadeOnDelete();
            $table->foreignId('technical_account_id')
                ->nullable()
                ->constrained('technical_accounts')
                ->nullOnDelete();
            $table->string('technical_account_name');
            $table->string('mode', 24);
            $table->json('criteria');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('deleted_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'gc_technical_delete_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_technical_delete_tasks');
    }
};
