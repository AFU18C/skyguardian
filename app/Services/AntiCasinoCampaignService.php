<?php

namespace App\Services;

use App\Models\GroupChannelBot;
use App\Models\GroupChannelPublication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AntiCasinoCampaignService
{
    public const TOTAL_POSTS = 1000;

    private const CAMPAIGN_TAG = '#AntiCasino1000';

    private const STATUS_PATH = 'anti-casino-campaign-status.json';

    private const TIMEZONE = 'Europe/Kyiv';

    private const HEADLINES = [
        '🎰 НОВИНИ ЧИ ВОРОНКА КАЗИНО?',
        '🚨 ВОНИ ПРОДАЮТЬ КАЗИНО ВАШУ ДОВІРУ',
        '💸 КАНАЛ ЗАРОБИВ — ПІДПИСНИК ПРОГРАВ?',
        '⚠️ РЕКЛАМА КАЗИНО МІЖ ТРИВОГАМИ — ЦЕ НОРМАЛЬНО?',
        '🔥 МІЛЬЙОННА АУДИТОРІЯ ЯК ТОВАР ДЛЯ КАЗИНО',
        '🧨 СПОЧАТКУ НОВИНИ. ПОТІМ ДЕПОЗИТ.',
        '❗ ХТО ВІДПОВІСТЬ ЗА ПРОГРАНІ ЗАРПЛАТИ?',
        '🕳 КАЗИНО КУПУЄ МІСЦЕ У СТРІЧЦІ — А ХТО ПЛАТИТЬ ПОТІМ?',
        '📢 НАЗИВАТИ ЦЕ «РОЗВАГОЮ» — ЗРУЧНО, ПОКИ ПЛАТИТЬ КАЗИНО',
        '💰 ВАШУ УВАГУ ВЖЕ ПРОДАЛИ ІГОРНОМУ БІЗНЕСУ',
        '🚫 НЕ НОВИНА, А РЕКЛАМНИЙ ВХІД У КАЗИНО',
        '🧾 КОЛИ ГРОШІ КАЗИНО ВПЛИВАЮТЬ НА СТРІЧКУ',
        '🎯 ЦІЛЬ РЕКЛАМИ — НЕ ВАШ ВИГРАШ, А ВАШ ДЕПОЗИТ',
        '🔴 КАЗИНО ПЛАТИТЬ КАНАЛУ. ХТО ПЛАТИТЬ КАЗИНО?',
        '🛑 ДОВІРА ДО НОВИН НЕ ПОВИННА СТАВАТИ ФІШКАМИ',
        '📉 ЗАРПЛАТА ЕЛЕКТРИКА — НЕ БОНУСНИЙ БАНК КАЗИНО',
        '⚡ ТРИВОГА МИНЕ. БОРГ ПІСЛЯ КАЗИНО МОЖЕ ЗАЛИШИТИСЯ',
        '🧠 МАНІПУЛЯЦІЯ ПОЧИНАЄТЬСЯ ЗІ СЛІВ «СПРОБУЙ ЗІ 100 ГРИВЕНЬ»',
        '👁 ВЕЛИКИЙ КАНАЛ НЕСЕ ВЕЛИКУ ВІДПОВІДАЛЬНІСТЬ',
        '🔎 ПЕРЕВІРЯЄМО, ХТО ВЕДЕ ЛЮДЕЙ У КАЗИНО',
    ];

    private const OPENINGS = [
        'Коли новинний канал роками збирає довіру, а потім веде аудиторію на депозит, він уже працює не лише як медіа — він стає рекламним партнером грального бізнесу.',
        'Звичайна людина заходить по новини, а отримує заклик внести гроші, забрати фріспіни або повірити у легкий виграш. Саме так довіра перетворюється на трафік для казино.',
        'Канал отримує оплату за розміщення. Казино отримує нового гравця. Ризик програшу залишається електрику, водію, військовому, медику чи пенсіонеру.',
        'Реклама азартних ігор у новинній стрічці маскується під дружню пораду: маленький депозит, великий бонус, нібито простий виграш. Але це комерційна воронка.',
        'Коли поруч із повідомленнями про обстріли з’являється заклик поповнити казино, межа між суспільною користю та продажем аудиторії зникає.',
        'Великі канали люблять називати себе народними. Народність перевіряється просто: чи поведеш ти свого читача туди, де він може програти зарплату?',
        'Казино не купує банер. Воно купує довіру, яку канал зібрав на термінових новинах, людському страху та потребі швидко отримувати інформацію.',
        'Підписник бачить знайомий канал і знижує критичність. Саме тому реклама казино у великому медіа працює сильніше за звичайний банер.',
        'Власник каналу заробляє одразу. Підписник може платити довго — грошима, боргами та конфліктами в родині.',
        'Коли медіа заробляє на переходах у казино, читач має право питати: де закінчується редакційне рішення і починається інтерес рекламодавця?',
    ];

    private const QUESTIONS = [
        'Хто вони після цього: незалежні новинники чи посередники між казино та гаманцем підписника?',
        'Чому прибуток каналу важливіший за ризик, що звичайна людина програє гроші?',
        'Чи можна називати себе народним каналом і одночасно продавати народ казино?',
        'Скільки програних зарплат ховається за одним успішним рекламним розміщенням?',
        'Чому в рекламі показують бонуси й виграші, але не показують тих, хто втратив гроші?',
        'Хто компенсує електрику або водію втрату, коли рекламна обіцянка не спрацює?',
        'Чи залишаються новини незалежними, коли поруч стоять гроші грального бізнесу?',
        'Що важливіше великому каналу: довіра читача чи наступний рекламний контракт?',
        'Навіщо називати казино розвагою, якщо бізнес-модель тримається на програшах клієнтів?',
        'Чому канал бере гроші гарантовано, а підписнику пропонує лише шанс?',
    ];

    private const CALLS = [
        'Не віддавай зарплату рекламній воронці. Перевіряй факти й не переходь у казино з новинних каналів.',
        'Побачив рекламу казино — зроби скриншот, збережи посилання та перевір, чи відповідає вона закону.',
        'Новини мають попереджати про ризики, а не підштовхувати до депозиту.',
        'Довіра людей — не товар. Вимагай від великих каналів відмови від реклами азартних ігор.',
        'Підтримуй медіа, які не заробляють на можливих програшах власної аудиторії.',
    ];

    private const SOURCES = [
        [
            'fact' => '25 липня 2025 року PlayCity оштрафувало власника «Труха Україна» на 4,8 млн грн: державний моніторинг зафіксував систематичну незаконну рекламу азартних ігор, маніпулятивні фрази та відсутність попередження про лудоманію.',
            'url' => 'https://suspilne.media/1075787-derzagentstvo-plejsiti-ostrafuvalo-telegram-kanal-truha-ukraina-za-nelegalnu-reklamu-kazino/',
        ],
        [
            'fact' => 'У публічному дописі «Труха Україна» рекламувала BETON: 5 000 000 грн призових, реєстрацію, верифікацію та перший депозит; допис позначено #реклама.',
            'url' => 'https://t.me/s/truexanewsua/133384',
        ],
        [
            'fact' => 'У публічному дописі «Труха Україна» рекламувала Slot City: депозит від 500 грн, грошові нагороди, бонуси та фріспіни; допис позначено #реклама.',
            'url' => 'https://t.me/s/truexanewsua/116202',
        ],
        [
            'fact' => 'У публічному дописі «Труха Україна» пропонувала промокод і 100 фріспінів у Slot City; допис позначено #реклама.',
            'url' => 'https://t.me/s/truexanewsua/115387',
        ],
        [
            'fact' => 'У публічній стрічці «Труха Україна» рекламувала ставки на ЧС-2026, бонус до депозиту та фрібети від BETON.',
            'url' => 'https://t.me/s/truexanewsua/77663',
        ],
    ];

    public function launch(): int
    {
        $bot = GroupChannelBot::query()
            ->where('is_active', true)
            ->where('chat_type', 'channel')
            ->whereNotNull('chat_id')
            ->orderByDesc('last_manual_check_at')
            ->orderByDesc('id')
            ->first();

        if (! $bot) {
            throw new RuntimeException('Не найден активный Telegram-канал с заполненным Chat ID.');
        }

        $this->enablePublicationModules($bot);

        $startKyiv = CarbonImmutable::now(self::TIMEZONE)->addMinute()->startOfMinute();
        $endKyiv = $startKyiv->setTime(8, 0);

        if ($endKyiv->lessThanOrEqualTo($startKyiv)) {
            $endKyiv = $endKyiv->addDay();
        }

        $lastSlotKyiv = $endKyiv->subSeconds(5);
        $stepSeconds = max(1, ($lastSlotKyiv->timestamp - $startKyiv->timestamp) / (self::TOTAL_POSTS - 1));
        $created = 0;

        for ($index = 1; $index <= self::TOTAL_POSTS; $index++) {
            $marker = sprintf('#AC%04d', $index);

            if ($bot->publications()->where('text', 'like', '%'.$marker.'%')->exists()) {
                continue;
            }

            $filename = sprintf('anti-casino-%04d.png', $index);
            $storedPath = $this->storeImage($bot, $filename, $index);
            $scheduledKyiv = $startKyiv->addSeconds((int) round(($index - 1) * $stepSeconds));

            $bot->publications()->create([
                'type' => GroupChannelPublication::TYPE_PHOTO,
                'text' => $this->caption($index),
                'media_paths' => [[
                    'path' => $storedPath,
                    'name' => $filename,
                    'mime' => 'image/png',
                ]],
                'buttons' => [[[
                    'text' => 'Відкрити SkyGuardian',
                    'url' => 'https://skyguardian.pp.ua/',
                ]]],
                'reactions' => [],
                'disable_notification' => false,
                'status' => GroupChannelPublication::STATUS_SCHEDULED,
                'scheduled_at' => $scheduledKyiv->setTimezone(config('app.timezone', 'UTC')),
            ]);

            $created++;
        }

        $this->writeStatus();

        return $created;
    }

    public function writeStatus(): void
    {
        $query = GroupChannelPublication::query()
            ->where('text', 'like', '%'.self::CAMPAIGN_TAG.'%');

        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $first = (clone $query)->oldest('scheduled_at')->first();
        $last = (clone $query)->latest('scheduled_at')->first();
        $latestSent = (clone $query)->where('status', GroupChannelPublication::STATUS_SENT)->latest('sent_at')->first();

        Storage::disk('public')->put(
            self::STATUS_PATH,
            json_encode([
                'campaign' => 'anti-casino-1000',
                'generated_at' => now()->toIso8601String(),
                'total' => array_sum($statusCounts),
                'statuses' => $statusCounts,
                'first_scheduled_at' => $first?->scheduled_at?->toIso8601String(),
                'last_scheduled_at' => $last?->scheduled_at?->toIso8601String(),
                'latest_sent_at' => $latestSent?->sent_at?->toIso8601String(),
                'latest_telegram_message_id' => $latestSent?->telegram_message_id,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function caption(int $index): string
    {
        $headline = self::HEADLINES[($index - 1) % count(self::HEADLINES)];
        $opening = self::OPENINGS[intdiv($index - 1, count(self::HEADLINES)) % count(self::OPENINGS)];
        $question = self::QUESTIONS[intdiv($index - 1, 7) % count(self::QUESTIONS)];
        $call = self::CALLS[intdiv($index - 1, 13) % count(self::CALLS)];
        $source = self::SOURCES[($index - 1) % count(self::SOURCES)];
        $marker = sprintf('#AC%04d', $index);

        return implode("\n\n", [
            $headline,
            $opening,
            'Факт: '.$source['fact'],
            $question,
            $call,
            'Джерело: '.$source['url'],
            'SkyGuardian — карта загроз без реклами казино: https://skyguardian.pp.ua/',
            self::CAMPAIGN_TAG.' '.$marker.' #ТрухаУкраїна #ТрухаУкраина #РекламаКазино #ОнлайнКазино #Лудоманія #НовиниУкраїни #SkyGuardian',
        ]);
    }

    private function storeImage(GroupChannelBot $bot, string $filename, int $index): string
    {
        $storedPath = 'group-channel-publications/'.$bot->id.'/anti-casino-1000/'.$filename;

        if (! Storage::disk('local')->put($storedPath, $this->generatePng($index))) {
            throw new RuntimeException('Не удалось сохранить изображение '.$filename.'.');
        }

        return $storedPath;
    }

    private function generatePng(int $index): string
    {
        $width = 256;
        $height = 256;
        $background = 1 + (($index - 1) % 4);
        $pixels = str_repeat(chr($background), $width * $height);

        $stripeColor = 5 + ($index % 3);
        $stripeOffset = $index % 18;

        for ($x = $stripeOffset; $x < $width; $x += 18) {
            $this->drawRect($pixels, $width, $x, 0, min($x + 2, $width - 1), $height - 1, $stripeColor);
        }

        $this->drawRect($pixels, $width, 35, 58, 220, 176, 9);
        $this->drawRect($pixels, $width, 43, 66, 212, 168, 10);

        for ($reel = 0; $reel < 3; $reel++) {
            $left = 52 + ($reel * 54);
            $this->drawRect($pixels, $width, $left, 82, $left + 42, 150, 11);
            $this->drawRect($pixels, $width, $left + 3, 85, $left + 39, 147, 12);
            $symbol = ($index + ($reel * 3)) % 5;
            $this->drawSymbol($pixels, $width, $left + 21, 116, $symbol, 13 + (($index + $reel) % 3));
        }

        $this->drawTriangle($pixels, $width, 128, 18, 93, 52, 163, 52, 14);
        $this->drawRect($pixels, $width, 124, 29, 132, 43, 9);
        $this->drawRect($pixels, $width, 124, 46, 132, 50, 9);
        $this->drawLine($pixels, $width, 30, 196, 226, 42, 15, 7);

        for ($coin = 0; $coin < 8; $coin++) {
            $seed = ($index * 37) + ($coin * 53);
            $cx = 18 + ($seed % 220);
            $cy = 183 + (($seed >> 3) % 45);
            $this->drawCircle($pixels, $width, $cx, $cy, 4 + ($seed % 5), 8);
        }

        $digits = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $x = 75;

        foreach (str_split($digits) as $digit) {
            $this->drawDigit($pixels, $width, $x, 222, (int) $digit, 14, 3);
            $x += 28;
        }

        $raw = '';

        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00".substr($pixels, $y * $width, $width);
        }

        $palette = [
            [0, 0, 0],
            [8, 20, 38],
            [25, 18, 52],
            [38, 16, 28],
            [9, 42, 42],
            [15, 58, 92],
            [66, 36, 110],
            [85, 28, 45],
            [255, 205, 40],
            [20, 20, 24],
            [70, 75, 84],
            [226, 230, 235],
            [245, 247, 250],
            [26, 150, 96],
            [255, 70, 70],
            [220, 25, 55],
        ];
        $plte = '';

        foreach ($palette as $color) {
            $plte .= chr($color[0]).chr($color[1]).chr($color[2]);
        }

        $header = pack('NNC5', $width, $height, 8, 3, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', $header)
            .$this->pngChunk('PLTE', $plte)
            .$this->pngChunk('IDAT', gzcompress($raw, 9))
            .$this->pngChunk('IEND', '');
    }

    private function drawSymbol(string &$pixels, int $width, int $cx, int $cy, int $symbol, int $color): void
    {
        match ($symbol) {
            0 => $this->drawDigit($pixels, $width, $cx - 8, $cy - 14, 7, $color, 4),
            1 => $this->drawCircle($pixels, $width, $cx, $cy, 12, $color),
            2 => $this->drawTriangle($pixels, $width, $cx, $cy - 14, $cx - 14, $cy + 12, $cx + 14, $cy + 12, $color),
            3 => $this->drawRect($pixels, $width, $cx - 13, $cy - 8, $cx + 13, $cy + 8, $color),
            default => $this->drawLine($pixels, $width, $cx - 12, $cy - 12, $cx + 12, $cy + 12, $color, 4),
        };

        if ($symbol === 4) {
            $this->drawLine($pixels, $width, $cx - 12, $cy + 12, $cx + 12, $cy - 12, $color, 4);
        }
    }

    private function drawDigit(string &$pixels, int $width, int $x, int $y, int $digit, int $color, int $scale): void
    {
        $patterns = [
            ['111', '101', '101', '101', '111'],
            ['010', '110', '010', '010', '111'],
            ['111', '001', '111', '100', '111'],
            ['111', '001', '111', '001', '111'],
            ['101', '101', '111', '001', '001'],
            ['111', '100', '111', '001', '111'],
            ['111', '100', '111', '101', '111'],
            ['111', '001', '010', '010', '010'],
            ['111', '101', '111', '101', '111'],
            ['111', '101', '111', '001', '111'],
        ];

        foreach ($patterns[$digit] as $row => $pattern) {
            foreach (str_split($pattern) as $column => $bit) {
                if ($bit === '1') {
                    $this->drawRect(
                        $pixels,
                        $width,
                        $x + ($column * $scale),
                        $y + ($row * $scale),
                        $x + (($column + 1) * $scale) - 1,
                        $y + (($row + 1) * $scale) - 1,
                        $color,
                    );
                }
            }
        }
    }

    private function drawRect(string &$pixels, int $width, int $x1, int $y1, int $x2, int $y2, int $color): void
    {
        $height = intdiv(strlen($pixels), $width);
        $x1 = max(0, min($width - 1, $x1));
        $x2 = max(0, min($width - 1, $x2));
        $y1 = max(0, min($height - 1, $y1));
        $y2 = max(0, min($height - 1, $y2));

        for ($y = min($y1, $y2); $y <= max($y1, $y2); $y++) {
            $length = abs($x2 - $x1) + 1;
            $offset = ($y * $width) + min($x1, $x2);
            substr_replace($pixels, str_repeat(chr($color), $length), $offset, $length);
        }
    }

    private function drawLine(string &$pixels, int $width, int $x1, int $y1, int $x2, int $y2, int $color, int $thickness = 1): void
    {
        $dx = abs($x2 - $x1);
        $sx = $x1 < $x2 ? 1 : -1;
        $dy = -abs($y2 - $y1);
        $sy = $y1 < $y2 ? 1 : -1;
        $error = $dx + $dy;

        while (true) {
            $radius = max(0, intdiv($thickness, 2));
            $this->drawRect($pixels, $width, $x1 - $radius, $y1 - $radius, $x1 + $radius, $y1 + $radius, $color);

            if ($x1 === $x2 && $y1 === $y2) {
                break;
            }

            $doubleError = 2 * $error;

            if ($doubleError >= $dy) {
                $error += $dy;
                $x1 += $sx;
            }

            if ($doubleError <= $dx) {
                $error += $dx;
                $y1 += $sy;
            }
        }
    }

    private function drawCircle(string &$pixels, int $width, int $cx, int $cy, int $radius, int $color): void
    {
        for ($y = -$radius; $y <= $radius; $y++) {
            $span = (int) floor(sqrt(max(0, ($radius * $radius) - ($y * $y))));
            $this->drawRect($pixels, $width, $cx - $span, $cy + $y, $cx + $span, $cy + $y, $color);
        }
    }

    private function drawTriangle(string &$pixels, int $width, int $x1, int $y1, int $x2, int $y2, int $x3, int $y3, int $color): void
    {
        $minY = min($y1, $y2, $y3);
        $maxY = max($y1, $y2, $y3);

        for ($y = $minY; $y <= $maxY; $y++) {
            $intersections = [];

            foreach ([[$x1, $y1, $x2, $y2], [$x2, $y2, $x3, $y3], [$x3, $y3, $x1, $y1]] as [$ax, $ay, $bx, $by]) {
                if ($ay === $by || $y < min($ay, $by) || $y > max($ay, $by)) {
                    continue;
                }

                $intersections[] = (int) round($ax + (($y - $ay) * ($bx - $ax) / ($by - $ay)));
            }

            if (count($intersections) >= 2) {
                sort($intersections);
                $this->drawRect($pixels, $width, $intersections[0], $y, $intersections[count($intersections) - 1], $y, $color);
            }
        }
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data) & 0xFFFFFFFF);
    }

    private function enablePublicationModules(GroupChannelBot $bot): void
    {
        $settings = $bot->module_settings ?? [];
        data_set($settings, 'publications.enabled', true);
        data_set($settings, 'scheduled_publications.enabled', true);
        $bot->update(['module_settings' => $settings]);
    }
}
