from pathlib import Path

path = Path('tests/Feature/GroupChannelAlertPublicationTest.php')
text = path.read_text()
old = '''        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');

            return str_ends_with($request->url(), '/sendMessage')
                && str_contains($text, 'Купʼянський район')
                && str_contains($text, 'Чугуївський район')
                && ! str_contains($text, 'м. Харків та тергромада')
                && str_contains($text, '🔄 Оновлено:');
        });
'''
new = '''        Http::assertSent(function (Request $request): bool {
            $text = (string) ($request['text'] ?? '');
            $activePart = strstr($text, '🔻 Відбій під час цієї тривоги:', true);

            return str_ends_with($request->url(), '/sendMessage')
                && is_string($activePart)
                && str_contains($activePart, 'Купʼянський район')
                && str_contains($activePart, 'Чугуївський район')
                && ! str_contains($activePart, 'м. Харків та тергромада')
                && str_contains($text, '🔻 Відбій під час цієї тривоги:')
                && str_contains($text, 'м. Харків та тергромада')
                && str_contains($text, '🔄 Оновлено:');
        });
'''
assert old in text
path.write_text(text.replace(old, new, 1))
