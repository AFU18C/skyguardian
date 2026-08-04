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
    ): string {
        $timeLabel = $kind === 'end' ? 'Відбій' : 'Початок';
        $message = '<b>'.self::escape(self::headline($kind, $threatType))."</b>\n\n";
        $message .= self::formatRegions($kind, $regions);

        foreach (self::unique($details) as $detail) {
            $message .= "\n\n🎯 ".self::escape(mb_substr($detail, 0, 500));
        }

        $message .= "\n\n🕒 <b>{$timeLabel}: ".self::escape($time).'</b>';

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
                ? '🟢 ВІДБІЙ ПОВІТРЯНОЇ ТРИВОГИ'
                : '🟢 ВІДБІЙ: '.mb_strtoupper($threatType);
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
        /** @var array<string, array{oblast: string, children: array<string, string>}> $groups */
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

        $blocks = [];

        foreach ($groups as $group) {
            $children = array_values($group['children']);
            usort($children, function (string $left, string $right): int {
                $rank = self::locationRank($left) <=> self::locationRank($right);

                return $rank !== 0 ? $rank : strnatcasecmp($left, $right);
            });

            $block = '📍 <b>'.self::escape($group['oblast']).'</b>';

            if ($children !== []) {
                $block .= $kind === 'end'
                    ? "\n\nВідбій оголошено:"
                    : "\n\nТривога оголошена:";

                foreach ($children as $child) {
                    $block .= "\n• ".self::escape($child);
                }
            }

            $blocks[] = $block;
        }

        return $blocks !== []
            ? implode("\n\n", $blocks)
            : '📍 <b>Невідома локація</b>';
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
