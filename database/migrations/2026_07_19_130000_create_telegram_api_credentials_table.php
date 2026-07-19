<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 80);
            $table->text('api_id');
            $table->text('api_hash');
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();
        });

        Schema::table('technical_telegram_accounts', function (Blueprint $table): void {
            $table->foreignId('telegram_api_credential_id')
                ->nullable()
                ->after('id')
                ->constrained('telegram_api_credentials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('technical_telegram_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('telegram_api_credential_id');
        });

        Schema::dropIfExists('telegram_api_credentials');
    }
};
