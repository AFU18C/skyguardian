<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->string('publication_identifier')->nullable()->after('identifier');
            $table->string('publication_format', 20)->default('original')->after('publication_identifier');
            $table->unsignedInteger('check_interval_seconds')->default(3)->after('publication_format');
            $table->text('keywords')->nullable()->after('check_interval_seconds');
            $table->text('stop_words')->nullable()->after('keywords');
            $table->boolean('append_custom_text')->default(false)->after('stop_words');
            $table->text('custom_text')->nullable()->after('append_custom_text');
            $table->boolean('remove_links')->default(true)->after('custom_text');
            $table->boolean('remove_hashtags')->default(true)->after('remove_links');
            $table->text('last_error')->nullable()->after('remove_hashtags');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn([
                'publication_identifier',
                'publication_format',
                'check_interval_seconds',
                'keywords',
                'stop_words',
                'append_custom_text',
                'custom_text',
                'remove_links',
                'remove_hashtags',
                'last_error',
            ]);
        });
    }
};
