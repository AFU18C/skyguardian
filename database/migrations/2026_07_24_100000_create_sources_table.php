<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('telegram');
            $table->string('identifier');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
