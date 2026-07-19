<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_telegram_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 80);
            $table->text('api_id');
            $table->text('api_hash');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('news_technical_telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_telegram_api_credential_id')->nullable()->constrained('news_telegram_api_credentials')->nullOnDelete();
            $table->string('label', 80)->nullable();
            $table->text('phone')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('telegram_id', 32)->nullable();
            $table->string('status', 32)->default('disconnected');
            $table->boolean('is_primary')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('news_sources', function (Blueprint $table): void {
            $table->dropForeign(['reader_account_id']);
            $table->dropForeign(['publisher_account_id']);
        });

        DB::table('news_sources')->update([
            'reader_account_id' => null,
            'publisher_account_id' => null,
            'autopublish_enabled' => false,
            'source_status' => 'not_checked',
            'destination_status' => 'not_checked',
        ]);

        Schema::table('news_sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('reader_account_id')->nullable()->change();
            $table->unsignedBigInteger('publisher_account_id')->nullable()->change();
            $table->foreign('reader_account_id')->references('id')->on('news_technical_telegram_accounts')->restrictOnDelete();
            $table->foreign('publisher_account_id')->references('id')->on('news_technical_telegram_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('news_sources', function (Blueprint $table): void {
            $table->dropForeign(['reader_account_id']);
            $table->dropForeign(['publisher_account_id']);
        });

        Schema::table('news_sources', function (Blueprint $table): void {
            $table->foreign('reader_account_id')->references('id')->on('technical_telegram_accounts')->restrictOnDelete();
            $table->foreign('publisher_account_id')->references('id')->on('technical_telegram_accounts')->restrictOnDelete();
        });

        Schema::dropIfExists('news_technical_telegram_accounts');
        Schema::dropIfExists('news_telegram_api_credentials');
    }
};
