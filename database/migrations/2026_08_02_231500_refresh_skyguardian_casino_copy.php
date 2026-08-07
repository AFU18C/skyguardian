<?php

use App\Models\GroupChannelPublication;
use Illuminate\Database\Migrations\Migration;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $path = resource_path('campaigns/skyguardian-start/posts.json');
        $posts = json_decode((string) file_get_contents($path), true);

        if (! is_array($posts)) {
            throw new RuntimeException('Не удалось прочитать тексты промо-кампании.');
        }

        foreach ($posts as $post) {
            $number = (int) ($post['number'] ?? 0);

            if ($number < 3 || empty($post['text'])) {
                continue;
            }

            $marker = sprintf('#SkyGuardianStart%02d', $number);

            GroupChannelPublication::query()
                ->where('status', GroupChannelPublication::STATUS_SCHEDULED)
                ->where('text', 'like', '%'.$marker.'%')
                ->update([
                    'text' => (string) $post['text'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Уже опубликованные публикации автоматически не меняются.
    }
};
