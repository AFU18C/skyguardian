<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BLOCK_ID = 'home-alert-map';

    public function up(): void
    {
        $home = DB::table('site_pages')
            ->where('system_key', 'home')
            ->first(['id', 'blocks']);

        if (! $home) {
            return;
        }

        $blocks = json_decode((string) $home->blocks, true);
        $blocks = is_array($blocks) ? $blocks : [];

        if (collect($blocks)->contains(fn ($block): bool => is_array($block) && ($block['type'] ?? null) === 'alert_map')) {
            return;
        }

        $blocks[] = [
            'id' => self::BLOCK_ID,
            'type' => 'alert_map',
            'hidden' => false,
            'data' => [
                'title' => 'Карта воздушных тревог Украины',
                'size' => 'standard',
                'show_link' => true,
            ],
        ];

        DB::table('site_pages')->where('id', $home->id)->update([
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $home = DB::table('site_pages')
            ->where('system_key', 'home')
            ->first(['id', 'blocks']);

        if (! $home) {
            return;
        }

        $blocks = json_decode((string) $home->blocks, true);
        if (! is_array($blocks)) {
            return;
        }

        $blocks = array_values(array_filter(
            $blocks,
            fn ($block): bool => ! is_array($block) || ($block['id'] ?? null) !== self::BLOCK_ID,
        ));

        DB::table('site_pages')->where('id', $home->id)->update([
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
