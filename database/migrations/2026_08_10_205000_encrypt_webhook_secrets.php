<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_channel_bots', function (Blueprint $table): void {
            $table->text('webhook_secret')->nullable()->change();
        });

        DB::table('group_channel_bots')
            ->whereNotNull('webhook_secret')
            ->orderBy('id')
            ->eachById(function (object $bot): void {
                $secret = (string) $bot->webhook_secret;
                try {
                    Crypt::decryptString($secret);

                    return;
                } catch (Throwable) {
                    DB::table('group_channel_bots')
                        ->where('id', $bot->id)
                        ->update(['webhook_secret' => Crypt::encryptString($secret)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('group_channel_bots')
            ->whereNotNull('webhook_secret')
            ->orderBy('id')
            ->eachById(function (object $bot): void {
                try {
                    $plain = Crypt::decryptString((string) $bot->webhook_secret);
                    DB::table('group_channel_bots')
                        ->where('id', $bot->id)
                        ->update(['webhook_secret' => $plain]);
                } catch (Throwable) {
                    // Already plaintext.
                }
            });

        Schema::table('group_channel_bots', function (Blueprint $table): void {
            $table->string('webhook_secret', 64)->nullable()->change();
        });
    }
};
