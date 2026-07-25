<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sources')->update([
            'last_message_id' => null,
            'last_success_at' => null,
        ]);
    }

    public function down(): void
    {
        // Cursor values cannot be reconstructed after reset.
    }
};
