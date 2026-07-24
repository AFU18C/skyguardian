<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 20)->default('news')->index();
            $table->string('name', 100);
            $table->text('api_id');
            $table->text('api_hash');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['purpose', 'name']);
        });

        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->foreignId('telegram_app_id')
                ->nullable()
                ->after('id')
                ->constrained('telegram_apps')
                ->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('status');
            $table->longText('session_payload')->nullable()->after('last_error');
            $table->timestamp('session_saved_at')->nullable()->after('session_payload');
            $table->timestamp('last_attempt_at')->nullable()->after('connected_at');
            $table->timestamp('last_success_at')->nullable()->after('last_attempt_at');
            $table->timestamp('flood_wait_until')->nullable()->after('last_success_at')->index();
        });

        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->text('api_id')->nullable()->change();
            $table->text('api_hash')->nullable()->change();
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropUnique(['type', 'identifier']);
            $table->dropUnique(['telegram_account_id', 'peer_id']);
            $table->string('purpose', 20)->nullable()->after('id')->index();
            $table->string('publication_peer_id')->nullable()->after('publication_identifier');
            $table->unsignedBigInteger('last_message_id')->nullable()->after('check_interval_seconds');
            $table->timestamp('next_check_at')->nullable()->after('last_message_id')->index();
            $table->timestamp('last_checked_at')->nullable()->after('next_check_at');
            $table->timestamp('last_success_at')->nullable()->after('last_checked_at');
            $table->timestamp('last_manual_checked_at')->nullable()->after('last_success_at');
            $table->timestamp('flood_wait_until')->nullable()->after('last_manual_checked_at');
            $table->boolean('is_available')->nullable()->after('flood_wait_until');
            $table->boolean('resume_from_latest')->default(true)->after('is_available');
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('resume_from_latest');
            $table->unique(
                ['telegram_account_id', 'peer_id', 'publication_peer_id'],
                'sources_account_peers_unique',
            );
        });

        Schema::create('news_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('telegram_message_id');
            $table->string('grouped_id')->nullable();
            $table->json('message_ids');
            $table->string('source_peer_id');
            $table->string('destination_peer_id');
            $table->char('dedupe_key', 64)->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('destination_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'telegram_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_publications');

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropUnique('sources_account_peers_unique');
            $table->dropColumn([
                'publication_peer_id',
                'purpose',
                'last_message_id',
                'next_check_at',
                'last_checked_at',
                'last_success_at',
                'last_manual_checked_at',
                'flood_wait_until',
                'is_available',
                'resume_from_latest',
                'consecutive_failures',
            ]);
            $table->unique(['type', 'identifier']);
            $table->unique(['telegram_account_id', 'peer_id']);
        });

        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('telegram_app_id');
            $table->dropColumn([
                'is_active',
                'session_payload',
                'session_saved_at',
                'last_attempt_at',
                'last_success_at',
                'flood_wait_until',
            ]);
        });

        Schema::dropIfExists('telegram_apps');
    }
};
