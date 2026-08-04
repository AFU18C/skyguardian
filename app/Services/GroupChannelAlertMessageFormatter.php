<?php

namespace App\Services;

final class GroupChannelAlertMessageFormatter
{
    /**
     * @param  array<int, string>  $regions
     * @param  array<int, string>  $details
     */
    public static function render(
        string $kind,
        string $threatType,
        string $time,
        array $regions,
        array $details = [],
        ?string $startTime = null,
        ?int $durationMinutes = null,
    ): string {
        $message = '<b>'.self::escape(self::headline($kind, $threatType))."</b>\n";
        $message .= "━━━━━━━━━━━━━━\n\n";
        $message .= self::formatRegions($kind, $regions);

        if ($kind !== 'end') {
            foreach (self::unique($details) as $detail) {
                $message .= "\n\n🎯 ".self::escape(mb_substr($detail, 0, 500));
            }

            $message .= "\n\n🕒 Початок: <b>".self::escape($time).'</b>';
            $message .= "\n🔄 Оновлено: <b>".self::escape(
                now('Europe/Kyiv')->format('H:i'),
            ).'</b>';
        } else {
            if ($startTime !== null && trim($startTime) !== '') {
                $message .= "\n\n🕒 <b>".self::escape($startTime)
                    .' → '.self::escape($time).'</b>';
            } else {
                $message .= "\n\n🕒 Відбій: <b>".self::escape($time).'</b>';
            }

            if ($durationMinutes !== null) {
                $message .= "\n⏱ Тривалість: <b>".self::escape(
                    self::formatDuration($durationMinutes),
                ).'</b>';
            }

            $message .= "\n\n📂 <b>Деталі завершеної тривоги</b>";
            $message .= "\n".self::formatCompletedAlertDetails(
                $threatType,
                $regions,
                $startTime,
            );
        }

        if (mb_strlen($message) <= 4096) {
            return $message;
        }

        return self::escape(mb_substr(html_entity_decode(strip_tags($message)), 0, 3800));
    }

    private static function headline(string $kind, string $threatType): string
    {
        $threatType = trim($threatType);
        $normalized = mb_strtolower($threatType);
        $isAirRaid = $normalized === '' || str_contains($normalized, 'повітряна тривога');

        if ($kind === 'end') {
            return $isAirRaid
                ? '🟢 ТРИВОГУ ЗАВЕРШЕНО'
                : '🟢 ЗАГРОЗУ ЗАВЕРШЕНО';
        }

        return $isAirRaid
            ? '🚨 ПОВІТРЯНА ТРИВОГА'
            : '🚨 '.mb_strtoupper($threatType);
    }

    /**
     * @param  array<int, string>  $regions
     */
    private static function formatRegions(string $kind, array $regions): string
    {
        $groups = self::groupRegions($regions);
        $blocks = [];

        foreach ($groups as $group) {
            $children = self::sortedChildren($group['children']);
            $isEnd = $kind === 'end';
            $block = '📍 <b>'.self::escape($group['oblast'])."</b>\n\n";
            $block .= $isEnd
                ? '✅ <b>СТАТУС: БЕЗПЕЧНО</b>'
                : '🔴 <b>СТАТУС: АКТИВНА</b>';

            if (! $isEnd) {
                $block .= "\n\n<b>Активні території:</b>";
                $block .= self::formatLocationList($children);
            }

            $blocks[] = $block;
        }

        return $blocks !== []
            ? implode("\n\n", $blocks)
            : "📍 <b>Невідома локація</b>\n\n🔴 <b>СТАТУС: АКТИВНА</b>";
    }

    /**
     * @param  array<int, string>  $regions
     */
    private static function formatCompletedAlertDetails(
        string $threatType,
        array $regions,
        ?string $startTime,
    ): string {
        $groups = self::groupRegions($regions);
        $locations = [];

        foreach ($groups as $group) {
            foreach (self::sortedChildren($group['children']) as $child) {
                $locations[mb_strtolower($child)] = $child;
            }
        }

        $details = '<b>'.self::escape(self::headline('start', $threatType))."</b>\n";
        $details .= '🔴 <b>СТАТУС БУВ: АКТИВНА</b>';
        $details .= "\n\n<b>Території:</b>";
        $details .= self::formatLocationList(array_values($locations));

        if ($startTime !== null && trim($startTime) !== '') {
            $details .= "\n\n🕒 Початок: <b>".self::escape($startTime).'</b>';
        }

        return '<blockquote expandable>'.$details.'</blockquote>';
    }

    /**
     * @param  array<int, string>  $regions
     * @return array<string, array{oblast: string, children: array<string, string>}>
     */
    private static function groupRegions(array $regions): array
    {
        $groups = [];

        foreach (self::unique($regions) as $region) {
            $parts = array_values(array_filter(
                array_map('trim', explode(' — ', $region)),
                fn (string $part): bool => $part !== '',
            ));

            if ($parts === []) {
                continue;
            }

            $oblast = $parts[0];
            $key = mb_strtolower($oblast);
            $groups[$key] ??= ['oblast' => $oblast, 'children' => []];

            if (count($parts) > 1) {
                $child = (string) end($parts);
                $groups[$key]['children'][mb_strtolower($child)] = $child;
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, string>  $children
     * @return array<int, string>
     */
    private static function sortedChildren(array $children): array
    {
        $values = array_values($children);
        usort($values, function (string $left, string $right): int {
            $rank = self::locationRank($left) <=> self::locationRank($right);

            return $rank !== 0 ? $rank : strnatcasecmp($left, $right);
        });

        return $values;
    }

    /**
     * @param  array<int, string>  $locations
     */
    private static function formatLocationList(array $locations): string
    {
        if ($locations === []) {
            return "\n› Уся територія";
        }

        $list = '';

        foreach ($locations as $location) {
            $list .= "\n› ".self::escape($location);
        }

        return $list;
    }

    private static function formatDuration(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $remainingMinutes = $minutes % 60;
        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' д';
        }

        if ($hours > 0) {
            $parts[] = $hours.' год';
        }

        if ($remainingMinutes > 0 || $parts === []) {
            $parts[] = $remainingMinutes.' хв';
        }

        return implode(' ', $parts);
    }

    private static function locationRank(string $location): int
    {
        if (preg_match('/район$/ui', $location)) {
            return 10;
        }

        if (preg_match('/^м\.\s/ui', $location)) {
            return 20;
        }

        if (str_contains(mb_strtolower($location), 'громад')) {
            return 30;
        }

        return 40;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private static function unique(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            $value = trim($value);

            if ($value !== '') {
                $unique[mb_strtolower($value)] = $value;
            }
        }

        return array_values($unique);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
