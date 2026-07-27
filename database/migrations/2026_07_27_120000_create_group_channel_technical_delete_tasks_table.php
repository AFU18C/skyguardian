<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'group_channel_technical_delete_tasks';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $this->columns($table);
                $this->constraints($table);
            });

            return;
        }

        $this->repairExistingMysqlTable();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function columns(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('group_channel_bot_id');
        $table->unsignedBigInteger('technical_account_id')->nullable();
        $table->string('technical_account_name');
        $table->string('mode', 24);
        $table->json('criteria');
        $table->string('status', 24)->default('pending');
        $table->unsignedInteger('matched_count')->default(0);
        $table->unsignedInteger('deleted_count')->default(0);
        $table->unsignedInteger('failed_count')->default(0);
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->text('last_error')->nullable();
        $table->timestamps();
    }

    private function constraints(Blueprint $table): void
    {
        $table->foreign('group_channel_bot_id', 'gc_td_bot_fk')
            ->references('id')
            ->on('group_channel_bots')
            ->cascadeOnDelete();
        $table->foreign('technical_account_id', 'gc_td_account_fk')
            ->references('id')
            ->on('technical_accounts')
            ->nullOnDelete();
        $table->index(['status', 'created_at'], 'gc_technical_delete_status_created_idx');
    }

    private function repairExistingMysqlTable(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        $foreignColumns = collect(DB::select(
            <<<'SQL'
                SELECT COLUMN_NAME AS column_name
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA = ?
                  AND TABLE_NAME = ?
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            SQL,
            [$database, self::TABLE],
        ))->pluck('column_name');
        $indexNames = collect(DB::select(
            <<<'SQL'
                SELECT DISTINCT INDEX_NAME AS index_name
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = ?
                  AND TABLE_NAME = ?
            SQL,
            [$database, self::TABLE],
        ))->pluck('index_name');

        Schema::table(self::TABLE, function (Blueprint $table) use ($foreignColumns, $indexNames): void {
            if (! $foreignColumns->contains('group_channel_bot_id')) {
                $table->foreign('group_channel_bot_id', 'gc_td_bot_fk')
                    ->references('id')
                    ->on('group_channel_bots')
                    ->cascadeOnDelete();
            }

            if (! $foreignColumns->contains('technical_account_id')) {
                $table->foreign('technical_account_id', 'gc_td_account_fk')
                    ->references('id')
                    ->on('technical_accounts')
                    ->nullOnDelete();
            }

            if (! $indexNames->contains('gc_technical_delete_status_created_idx')) {
                $table->index(['status', 'created_at'], 'gc_technical_delete_status_created_idx');
            }
        });
    }
};
