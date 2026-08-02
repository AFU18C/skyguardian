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

        $fundraisingPost = <<<'TEXT'
💰 НЕ 50%. НЕ 70%. ПОКАЖІТЬ 100%.

«Труха Україна» збирає гроші для військових. Тоді питання просте: де єдиний публічний звіт по кожному збору — скільки зайшло, що купили, за якою ціною, кому передали і який залишок?

Скрін переказу — це не повний аудит. Якщо кожна гривня справді йде військовим, покажіть документи так, щоб перевірити міг кожен. Ми не звинувачуємо — ми вимагаємо прозорості.

Поки великі канали торгують увагою, SkyGuardian дає прямий доступ до карти загроз:
https://skyguardian.pp.ua/

#SkyGuardianStart #SkyGuardianStart08 #ТрухаУкраїна #ТрухаУкраина #Труха #ЗбірДляЗСУ #ДопомогаЗСУ #Благодійність #Прозорість #ЗСУ #НовиниУкраїни
TEXT;

        GroupChannelPublication::query()
            ->where('status', GroupChannelPublication::STATUS_SCHEDULED)
            ->where('text', 'like', '%#SkyGuardianStart08%')
            ->update([
                'text' => $fundraisingPost,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Уже опубликованные и запланированные посты автоматически не откатываются.
    }
};
