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
            $table->unsignedBigInteger('group_channel_bot_id');
            $table->unsignedBigInteger('technical_account_id')->nullable();
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

            $table->foreign('group_channel_bot_id', 'gc_td_bot_fk')
                ->references('id')
                ->on('group_channel_bots')
                ->cascadeOnDelete();
            $table->foreign('technical_account_id', 'gc_td_account_fk')
                ->references('id')
                ->on('technical_accounts')
                ->nullOnDelete();
            $table->index(['status', 'created_at'], 'gc_technical_delete_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_technical_delete_tasks');
    }
};
