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
            $table->string('name');
            $table->string('type', 20);
            $table->text('address');
            $table->text('publication_chat');
            $table->unsignedSmallInteger('check_interval');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_sources');
    }
};
