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
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_channel_bots');
    }
};
