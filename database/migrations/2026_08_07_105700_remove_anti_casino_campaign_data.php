<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('group_channel_publications')) {
            return;
        }

        $botIds = [];

        DB::table('group_channel_publications')
            ->where('text', 'like', '%#AntiCasino1000%')
            ->orderBy('id')
            ->chunkById(100, function ($publications) use (&$botIds): void {
                foreach ($publications as $publication) {
                    $botIds[(int) $publication->group_channel_bot_id] = true;
                    $mediaPaths = is_string($publication->media_paths)
                        ? json_decode($publication->media_paths, true)
                        : $publication->media_paths;

                    foreach (is_array($mediaPaths) ? $mediaPaths : [] as $media) {
                        $path = is_array($media) ? ($media['path'] ?? null) : null;

                        if (is_string($path) && $path !== '') {
                            Storage::disk('local')->delete($path);
                        }
                    }
                }
            });

        DB::table('group_channel_publications')
            ->where('text', 'like', '%#AntiCasino1000%')
            ->delete();

        foreach (array_keys($botIds) as $botId) {
            Storage::disk('local')->deleteDirectory('group-channel-publications/'.$botId.'/anti-casino-1000');
        }

        Storage::disk('public')->delete([
            'anti-casino-campaign-status.json',
            'anti-casino-visual-refresh.json',
        ]);
    }

    public function down(): void
    {
        // Удалённая временная кампания не восстанавливается.
    }
};
