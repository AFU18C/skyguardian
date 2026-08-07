from pathlib import Path

path = Path('app/Services/GroupChannelAlertPublicationService.php')
text = path.read_text()

old = '''            $isRefresh = $card !== null || $startedAt->lessThan($now->subMinute());
            $historySince = $card?->created_at?->toImmutable() ?? $now;
            $cardPayload = $this->renderActiveCardPayload(
                $bot,
                $states,
                $startedAt,
                $historySince,
                $now,
                $isRefresh,
            );
'''
new = '''            $isRefresh = $card !== null || $startedAt->lessThan($now->subMinute());
            $cycleStartedAt = $card?->started_at?->toImmutable();
            if (! $cycleStartedAt || $startedAt->lessThan($cycleStartedAt)) {
                $cycleStartedAt = $startedAt;
            }
            $cardPayload = $this->renderActiveCardPayload(
                $bot,
                $states,
                $startedAt,
                $cycleStartedAt,
                $now,
                $isRefresh,
            );
'''
assert old in text
text = text.replace(old, new, 1)

old = '''                        'started_at' => $startedAt,
                        'published_at' => $card?->published_at,
'''
new = '''                        'started_at' => $cycleStartedAt,
                        'published_at' => $card?->published_at,
'''
assert old in text
text = text.replace(old, new, 1)

old = '''                    'started_at' => $startedAt,
                    'published_at' => $now,
'''
new = '''                    'started_at' => $cycleStartedAt,
                    'published_at' => $now,
'''
assert old in text
text = text.replace(old, new, 1)

text = text.replace('CarbonImmutable $historySince,', 'CarbonImmutable $cycleStartedAt,', 2)
text = text.replace('            $historySince,\n            $oblast,', '            $cycleStartedAt,\n            $oblast,', 1)
text = text.replace("            ->where('event_at', '>=', $historySince)", "            ->where('event_at', '>=', $cycleStartedAt)", 1)

old = '''        $heading = '🔻 Відбій під час цієї тривоги:';
        $section = "\\n\\n{$heading}\\n{$history}";
        $updatedMarker = "\\n🔄";
'''
new = '''        $heading = '🔻 Відбій під час цієї тривоги:';
        $separator = "\\n\\n{$heading}\\n";
        $availableHistoryUnits = 4096
            - $this->telegramUtf16Length($text)
            - $this->telegramUtf16Length($separator);

        if ($availableHistoryUnits <= 1) {
            return ['text' => $text];
        }

        if ($this->telegramUtf16Length($history) > $availableHistoryUnits) {
            $history = rtrim(mb_substr($history, 0, max(1, $availableHistoryUnits - 1))).'…';
        }

        $section = $separator.$history;
        $updatedMarker = "\\n🔄";
'''
assert old in text
text = text.replace(old, new, 1)
path.write_text(text)
