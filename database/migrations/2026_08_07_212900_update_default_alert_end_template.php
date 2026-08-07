<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY = "✅ ВІДБІЙ ТРИВОГИ\n\n📍 {region}\n🕒 Відбій: {time}";

    private const CURRENT = "✅ ВІДБІЙ ТРИВОГИ\n\n{clear_blocks}";

    public function up(): void
    {
        $this->replaceTemplate(self::LEGACY, self::CURRENT);
    }

    public function down(): void
    {
        $this->replaceTemplate(self::CURRENT, self::LEGACY);
    }

    private function replaceTemplate(string $from, string $to): void
    {
        DB::table('group_channel_bots')
            ->select(['id', 'module_settings'])
            ->orderBy('id')
            ->chunkById(100, function ($bots) use ($from, $to): void {
                foreach ($bots as $bot) {
                    $settings = json_decode((string) $bot->module_settings, true);

                    if (! is_array($settings)) {
                        continue;
                    }

                    $current = data_get($settings, 'alert_publications.end_template');

                    if (! is_string($current)
                        || $this->normalize($current) !== $this->normalize($from)) {
                        continue;
                    }

                    data_set($settings, 'alert_publications.end_template', $to);

                    DB::table('group_channel_bots')
                        ->where('id', $bot->id)
                        ->update([
                            'module_settings' => json_encode(
                                $settings,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ),
                        ]);
                }
            });
    }

    private function normalize(string $template): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim($template));
    }
};
