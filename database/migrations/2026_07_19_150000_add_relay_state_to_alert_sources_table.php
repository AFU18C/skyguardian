<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_source_message_id')->nullable()->after('publish_as');
            $table->timestamp('last_received_at')->nullable()->after('last_source_message_id');
            $table->timestamp('last_published_at')->nullable()->after('last_received_at');
        });
    }

    public function down(): void
    {
        Schema::table('alert_sources', function (Blueprint $table): void {
            $table->dropColumn(['last_source_message_id', 'last_received_at', 'last_published_at']);
        });
    }
};