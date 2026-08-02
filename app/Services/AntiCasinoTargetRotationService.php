<?php

namespace App\Services;

use App\Models\GroupChannelPublication;

class AntiCasinoTargetRotationService
{
    public const TARGET_COUNT = 9;

    private const TARGET_MARKER = '#ChannelFocus';

    private const TARGETS = [
        [
            'name' => 'Харьков life | Харків',
            'handle' => '@kharkivlife',
            'url' => 'https://t.me/kharkivlife',
            'tags' => '#kharkivlife #ХарьковLife #ХарківLife',
            'confirmed' => false,
        ],
        [
            'name' => 'Життя ОБОЛОНЬ Київ',
            'handle' => '@obolonlife',
            'url' => 'https://t.me/obolonlife',
            'tags' => '#obolonlife #ОболоньLife #ОболоньКиїв',
            'confirmed' => false,
        ],
        [
            'name' => 'Всевидящее ОКО',
            'handle' => '@oko_ua',
            'url' => 'https://t.me/oko_ua',
            'tags' => '#oko_ua #ВсевидящееОКО #ОКОУкраїна',
            'confirmed' => false,
        ],
        [
            'name' => 'Труха Україна',
            'handle' => '@truexanewsua',
            'url' => 'https://t.me/truexanewsua',
            'tags' => '#truexanewsua #ТрухаУкраїна #ТрухаУкраина',
            'confirmed' => true,
        ],
        [
            'name' => 'Лачен пише',
            'handle' => '@lachentyt',
            'url' => 'https://t.me/lachentyt',
            'tags' => '#lachentyt #ЛаченПише #Лачен',
            'confirmed' => false,
        ],
        [
            'name' => 'Реальний Київ',
            'handle' => '@kievreal1',
            'url' => 'https://t.me/kievreal1',
            'tags' => '#kievreal1 #РеальнийКиїв #РеальныйКиев',
            'confirmed' => false,
        ],
        [
            'name' => 'Київ Оперативний',
            'handle' => '@kyivoperat',
            'url' => 'https://t.me/kyivoperat',
            'tags' => '#kyivoperat #КиївОперативний #КиевОперативный',
            'confirmed' => false,
        ],
        [
            'name' => 'Закритий Telegram-канал',
            'handle' => 'приватне посилання',
            'url' => 'https://t.me/+pFvHjtPUufZhMTFi',
            'tags' => '#TelegramКанал #ПеревіркаКаналів #РекламаКазино',
            'confirmed' => false,
        ],
        [
            'name' => 'Николаевский Ванёк',
            'handle' => '@vanek_nikolaev',
            'url' => 'https://t.me/vanek_nikolaev',
            'tags' => '#vanek_nikolaev #НиколаевскийВанёк #МиколаївськийВаньок',
            'confirmed' => false,
        ],
    ];

    public function refreshScheduled(): int
    {
        $updated = 0;

        GroupChannelPublication::query()
            ->where('status', GroupChannelPublication::STATUS_SCHEDULED)
            ->where('text', 'like', '%#AntiCasino1000%')
            ->orderBy('id')
            ->chunkById(100, function ($publications) use (&$updated): void {
                foreach ($publications as $publication) {
                    if (! preg_match('/#AC(\d{4})/u', $publication->text, $matches)) {
                        continue;
                    }

                    $index = (int) $matches[1];
                    $publication->update([
                        'text' => $this->withTarget($publication->text, $index),
                    ]);
                    $updated++;
                }
            });

        return $updated;
    }

    public function targetForIndex(int $index): array
    {
        return self::TARGETS[($index - 1) % self::TARGET_COUNT];
    }

    public function withTarget(string $caption, int $index): string
    {
        $target = $this->targetForIndex($index);
        $caption = preg_replace('/\n\n🎯 КАНАЛ У ФОКУСІ:.*?'.preg_quote(self::TARGET_MARKER, '/').'\d{2}/us', '', $caption) ?? $caption;
        $paragraphs = array_values(array_filter(explode("\n\n", trim($caption)), fn (string $part): bool => $part !== ''));

        $headline = $paragraphs[0] ?? '🚨 КРУПНІ КАНАЛИ ТА РЕКЛАМА КАЗИНО';
        $opening = $paragraphs[1] ?? 'Новинний канал не повинен перетворювати довіру людей на трафік для грального бізнесу.';
        $fact = $paragraphs[2] ?? 'Факт: реклама азартних ігор у новинних каналах має перевірятися публічно.';
        $call = $paragraphs[4] ?? 'Не переходь у казино з новинних каналів.';
        $source = $paragraphs[5] ?? 'Джерело: публічна стрічка Telegram.';
        $skyGuardian = $paragraphs[6] ?? 'SkyGuardian — карта загроз без реклами казино: https://skyguardian.pp.ua/';
        $marker = sprintf('%s%02d', self::TARGET_MARKER, (($index - 1) % self::TARGET_COUNT) + 1);

        $focus = $target['confirmed']
            ? "🎯 КАНАЛ У ФОКУСІ: {$target['name']} ({$target['handle']})\nУ публічній стрічці зафіксована реклама казино та ставок. Канал продав рекламодавцю не просто місце — він використав довіру своєї аудиторії."
            : "🎯 КАНАЛ У ФОКУСІ: {$target['name']} ({$target['handle']})\nПублічне питання: канал рекламував або планує рекламувати казино? Якщо так — він продає гральному бізнесу довіру підписників. Вимагаємо відкритої відповіді.";

        $tags = '#AntiCasino1000 #AC'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)." {$target['tags']} #РекламаКазино #ОнлайнКазино #Лудоманія #SkyGuardian {$marker}";
        $result = implode("\n\n", [$headline, $opening, $fact, $focus, $call, $source, $skyGuardian, $tags]);

        if (mb_strlen($result) <= 1024) {
            return $result;
        }

        $available = max(80, 1024 - mb_strlen(implode("\n\n", [$headline, $fact, $focus, $call, $source, $skyGuardian, $tags])) - 2);
        $opening = rtrim(mb_substr($opening, 0, $available), ' ,.;:—-').'…';

        return mb_substr(implode("\n\n", [$headline, $opening, $fact, $focus, $call, $source, $skyGuardian, $tags]), 0, 1024);
    }
}
