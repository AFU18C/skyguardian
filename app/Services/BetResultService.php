<?php

namespace App\Services;

use App\Models\Bet;
use App\Models\BettingSetting;

class BetResultService
{
    public function check(Bet $bet, BettingSetting $settings): array
    {
        return [
            'result' => 'pending',
            'result_note' => 'Автоматическое определение отключено. Проверьте официальный источник и установите результат вручную.',
            'result_checked_at' => now(),
        ];
    }

    public function settle(string $market, int $home, int $away): string
    {
        $total = $home + $away;
        if (preg_match('/Т([БМ])\s*([0-9]+(?:\.[02575])?)/u', $market, $m)) {
            $line = (float) $m[2];
            if ((float) $total === $line) {
                return 'refund';
            }

            return ($m[1] === 'Б' ? $total > $line : $total < $line) ? 'win' : 'loss';
        }
        if (str_starts_with($market, 'Обе забьют')) {
            $yes = $home > 0 && $away > 0;
            $wanted = ! str_contains(mb_strtolower($market), 'нет');

            return $yes === $wanted ? 'win' : 'loss';
        }
        if (preg_match('/^Ф([12])\s*([+-]?[0-9]+(?:\.[02575])?)/u', $market, $match)) {
            $adjusted = ($match[1] === '1' ? $home - $away : $away - $home) + (float) $match[2];

            return $adjusted > 0 ? 'win' : ($adjusted < 0 ? 'loss' : 'refund');
        }

        return match ($market) {
            'П1' => $home > $away ? 'win' : 'loss',
            'П2' => $away > $home ? 'win' : 'loss',
            '1X' => $home >= $away ? 'win' : 'loss',
            'X2' => $away >= $home ? 'win' : 'loss',
            '12' => $home !== $away ? 'win' : 'loss',
            default => 'pending',
        };
    }
}
