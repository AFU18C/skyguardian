<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('betting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technical_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('publication_bot_id')->nullable()->constrained('group_channel_bots')->nullOnDelete();
            $table->json('keywords');
            $table->unsignedSmallInteger('freshness_hours')->default(24);
            $table->unsignedTinyInteger('minimum_ai_score')->default(80);
            $table->unsignedSmallInteger('maximum_results')->default(10);
            $table->string('primary_source_name')->default('BETON');
            $table->string('primary_source_url')->default('https://beton.ua/sportsbook');
            $table->string('reserve_source_name')->nullable();
            $table->string('reserve_source_url')->nullable();
            $table->unsignedSmallInteger('found_retention_days')->default(7);
            $table->unsignedSmallInteger('rejected_retention_days')->default(7);
            $table->unsignedSmallInteger('completed_retention_days')->nullable();
            $table->timestamps();
        });

        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->index();
            $table->string('publication_guard', 64)->nullable()->unique();
            $table->string('status', 24)->default('found')->index();
            $table->string('sport')->nullable();
            $table->string('event_name');
            $table->string('home_team')->nullable();
            $table->string('away_team')->nullable();
            $table->string('tournament')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->string('external_event_id')->nullable();
            $table->string('market');
            $table->decimal('telegram_odds', 8, 3)->nullable();
            $table->decimal('primary_odds', 8, 3)->nullable();
            $table->decimal('reserve_odds', 8, 3)->nullable();
            $table->decimal('selected_odds', 8, 3)->nullable();
            $table->string('selected_odds_source')->nullable();
            $table->unsignedTinyInteger('ai_score')->default(0);
            $table->text('ai_reason')->nullable();
            $table->json('telegram_sources')->nullable();
            $table->json('odds_snapshot')->nullable();
            $table->timestamp('odds_checked_at')->nullable();
            $table->text('publication_text')->nullable();
            $table->foreignId('publication_bot_id')->nullable()->constrained('group_channel_bots')->nullOnDelete();
            $table->string('telegram_message_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('result', 16)->nullable()->index();
            $table->text('result_note')->nullable();
            $table->timestamp('result_checked_at')->nullable();
            $table->string('result_message_id')->nullable();
            $table->timestamp('result_sent_at')->nullable();
            $table->json('edit_history')->nullable();
            $table->timestamps();
        });

        Schema::create('bet_search_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('messages_found')->default(0);
            $table->unsignedInteger('bets_found')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_search_runs');
        Schema::dropIfExists('bets');
        Schema::dropIfExists('betting_settings');
    }
};
