<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('group_channel_publications')) {
            Storage::disk('public')->delete('skyguardian-campaign-status.json');

            return;
        }

        $botIds = [];

        DB::table('group_channel_publications')
            ->where('text', 'like', '%#SkyGuardianStart%')
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
            ->where('text', 'like', '%#SkyGuardianStart%')
            ->delete();

        foreach (array_keys($botIds) as $botId) {
            Storage::disk('local')->deleteDirectory('group-channel-publications/'.$botId.'/skyguardian-start');
        }

        Storage::disk('public')->delete('skyguardian-campaign-status.json');
    }

    public function down(): void
    {
        // Удалённая одноразовая промо-кампания не восстанавливается.
    }
};
