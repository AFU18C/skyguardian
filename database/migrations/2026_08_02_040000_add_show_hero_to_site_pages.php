<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_pages', function (Blueprint $table): void {
            $table->boolean('show_hero')->default(true)->after('excerpt');
        });
    }

    public function down(): void
    {
        Schema::table('site_pages', function (Blueprint $table): void {
            $table->dropColumn('show_hero');
        });
    }
};
