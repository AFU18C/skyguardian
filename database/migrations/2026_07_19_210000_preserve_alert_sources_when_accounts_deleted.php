<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_sources', function (Blueprint $table): void {
            $table->dropForeign(['reader_account_id']);
            $table->dropForeign(['publisher_account_id']);
            $table->unsignedBigInteger('reader_account_id')->nullable()->change();
            $table->unsignedBigInteger('publisher_account_id')->nullable()->change();
        });

        Schema::table('alert_sources', function (Blueprint $table): void {
            $table->foreign('reader_account_id')
                ->references('id')
                ->on('technical_telegram_accounts')
                ->restrictOnDelete();
            $table->foreign('publisher_account_id')
                ->references('id')
                ->on('technical_telegram_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alert_sources', function (Blueprint $table): void {
            $table->dropForeign(['reader_account_id']);
            $table->dropForeign(['publisher_account_id']);
        });

        Schema::table('alert_sources', function (Blueprint $table): void {
            $table->foreign('reader_account_id')
                ->references('id')
                ->on('technical_telegram_accounts')
                ->cascadeOnDelete();
            $table->foreign('publisher_account_id')
                ->references('id')
                ->on('technical_telegram_accounts')
                ->cascadeOnDelete();
        });
    }
};
