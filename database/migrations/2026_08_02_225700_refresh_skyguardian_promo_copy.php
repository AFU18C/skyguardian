<?php

use App\Models\GroupChannelPublication;
use Illuminate\Database\Migrations\Migration;

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
💰 «ТРУХА» КАЖЕ: 0% КОМІСІЇ. ТОДІ ПОКАЖІТЬ УСІ 100%.

Офіційний сайт фонду «Труха.Україна» заявляє: з пожертв на армію не забирають жодного відсотка, а роботу фонду оплачує медіагрупа.

У держреєстрах водночас видно: дохід фонду — 17,93 млн грн у 2024 році та 4,63 млн грн у 2025-му. Дохід рекламного ТОВ «Труха Україна» — 6,25 млн грн у 2024 році та 9,20 млн грн у 2025-му.

Це не звинувачення у крадіжці. Це вимога до найбільшої Telegram-мережі: опублікуйте єдину деталізовану таблицю по кожному збору — отримано, закуплено, ціна, передано, залишок. Коли збираєте мільйони для ЗСУ, слова «0%» мають підтверджуватися цифрами, які може перевірити кожен.

Джерела:
https://fund.truexa.com.ua/
https://opendatabot.ua/c/44820193
https://www.ukraine.com.ua/egrpou/45375684/

SkyGuardian — карта загроз без рекламної черги:
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
