<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->string('purpose', 20)->default('news')->after('name')->index();
        });

        DB::table('telegram_accounts')
            ->whereRaw("LOWER(name) LIKE '%тривог%' OR LOWER(name) LIKE '%alert%'")
            ->update(['purpose' => 'alerts']);
    }

    public function down(): void
    {
        Schema::table('telegram_accounts', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
