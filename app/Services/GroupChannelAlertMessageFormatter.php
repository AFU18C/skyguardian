<?php

namespace App\Services;

use App\Models\GroupChannelAlertEvent;
use App\Models\GroupChannelBot;
use Illuminate\Support\Collection;
use RuntimeException;

final class GroupChannelAlertMessageFormatter
{
    /**
     * @param  Collection<int, GroupChannelAlertEvent>  $events
     */
    public static function render(GroupChannelBot $bot, Collection $events): string
    {
        /** @var GroupChannelAlertEvent|null $first */
        $first = $events->first();

        if (! $first) {
            throw new RuntimeException('Невозможно сформировать сообщение без событий тревоги.');
        }

        $templateKey = $first->kind === GroupChannelAlertEvent::KIND_START
            ? 'start_template'
            : 'end_template';
        $default = $first->kind === GroupChannelAlertEvent::KIND_START
            ? GroupChannelBot::DEFAULT_ALERT_START_TEMPLATE
            : GroupChannelBot::DEFAULT_ALERT_END_TEMPLATE;
        $template = trim((string) $bot->moduleSetting(
            GroupChannelBot::MODULE_ALERT_PUBLICATIONS,
            $templateKey,
            $default,
        ));

        if ($template === '') {
            $template = $default;
        }

        $details = self::formatDetails($events);
        $hasDetailsVariable = str_contains($template, '{details}');
        $message = strtr(self::escape($template), [
            '{region}' => self::formatRegions($events),
            '{time}' => '<b>'.self::escape($first->event_at->timezone('Europe/Kyiv')->format('H:i')).'</b>',
            '{threat_type}' => self::escape(
                GroupChannelBot::ALERT_TYPES[$first->alert_type] ?? $first->alert_type,
            ),
            '{details}' => $details,
        ]);

        if ($first->kind === GroupChannelAlertEvent::KIND_START
            && $details !== ''
            && ! $hasDetailsVariable) {
            $detailsBlock = "🎯 {$details}";
            $message = str_contains($message, "\n🕒")
                ? str_replace("\n🕒", "\n{$detailsBlock}\n🕒", $message)
                : $message."\n{$detailsBlock}";
        }

        $message = self::boldFirstLine($message);
        $message = trim(preg_replace('/\n{3,}/', "\n\n", $message) ?? $message);

        if ($message === '') {
            throw new RuntimeException('Шаблон сообщения тревог сформировал пустой текст.');
        }

        if (mb_strlen($message) > 4096) {
            $plain = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return self::escape(mb_substr($plain, 0, 3800));
        }

        return $message;
    }

    /**
     * @param  Collection<int, GroupChannelAlertEvent>  $events
     */
    private static function formatRegions(Collection $events): string
    {
        /** @var array<string, array<string, string>> $groups */
        $groups = [];

        foreach ($events as $event) {
            $parts = array_values(array_filter(
                array_map('trim', explode(' — ', trim((string) $event->region_name))),
                fn (string $part): bool => $part !== '',
            ));

            if ($parts === []) {
                continue;
            }

            $oblast = $parts[0];
            $groupKey = mb_strtolower($oblast);
            $groups[$groupKey] ??= [
                'oblast' => $oblast,
                'children' => [],
            ];

            if (count($parts) < 2) {
                continue;
            }

            $child = (string) end($parts);
            $childKey = mb_strtolower($child);

            if ($childKey !== $groupKey) {
                $groups[$groupKey]['children'][$childKey] = $child;
            }
        }

        if ($groups === []) {
            return self::escape('Невідома локація');
        }

        $blocks = [];

        foreach ($groups as $group) {
            $children = array_values($group['children']);
            usort($children, function (string $left, string $right): int {
                $rank = self::locationRank($left) <=> self::locationRank($right);

                return $rank !== 0 ? $rank : strnatcasecmp($left, $right);
            });

            $block = '<b>'.self::escape($group['oblast']).'</b>';

            foreach ($children as $child) {
                $block .= "\n• ".self::escape($child);
            }

            $blocks[] = $block;
        }

        return implode("\n\n📍 ", $blocks);
    }

    private static function locationRank(string $location): int
    {
        if (preg_match('/район$/ui', $location)) {
            return 10;
        }

        if (preg_match('/^м\.\s/ui', $location) || str_contains(mb_strtolower($location), 'місто')) {
            return 20;
        }

        if (str_contains(mb_strtolower($location), 'громад')) {
            return 30;
        }

        return 40;
    }

    /**
     * @param  Collection<int, GroupChannelAlertEvent>  $events
     */
    private static function formatDetails(Collection $events): string
    {
        $details = $events
            ->pluck('details')
            ->filter(fn (mixed $detail): bool => is_string($detail) && trim($detail) !== '')
            ->map(fn (string $detail): string => trim($detail))
            ->unique(fn (string $detail): string => mb_strtolower($detail))
            ->values();
        $hidden = max(0, $details->count() - 5);
        $visible = $details
            ->take(5)
            ->map(fn (string $detail): string => self::escape(mb_substr($detail, 0, 500)))
            ->implode("\n🎯 ");

        if ($hidden > 0) {
            $visible .= "\n🎯 Ще {$hidden} уточнень";
        }

        return $visible;
    }

    private static function boldFirstLine(string $message): string
    {
        $lines = explode("\n", $message);

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            if (! str_contains($line, '<b>')) {
                $lines[$index] = '<b>'.$line.'</b>';
            }

            break;
        }

        return implode("\n", $lines);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
