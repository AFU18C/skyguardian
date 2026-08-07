from pathlib import Path
import re

root = Path('.')

history = root / 'app/Services/GroupChannelAlertHistoryService.php'
text = history.read_text()
old = 'public function __construct(private readonly GroupChannelTelegramService $telegram) {}'
new = 'public function __construct(private readonly DirectGroupChannelTelegramService $telegram) {}'
if old not in text:
    raise SystemExit('history constructor marker not found')
history.write_text(text.replace(old, new, 1))

publication = root / 'app/Services/GroupChannelAlertPublicationService.php'
text = publication.read_text()
marker = "        $data = (string) ($callback['data'] ?? '');\n\n"
insert = "        $data = (string) ($callback['data'] ?? '');\n\n        if (app(GroupChannelAlertHistoryService::class)->handleRefreshCallback($bot, $callback)) {\n            return true;\n        }\n\n"
if marker not in text:
    raise SystemExit('publication callback marker not found')
text = text.replace(marker, insert, 1)
pattern = re.compile(
    r"    public function handleHistoryStart\(GroupChannelBot \$bot, array \$message\): bool\n"
    r"    \{.*?\n"
    r"    \}\n\n"
    r"    private function deliverPending",
    re.S,
)
replacement = (
    "    public function handleHistoryStart(GroupChannelBot $bot, array $message): bool\n"
    "    {\n"
    "        return app(GroupChannelAlertHistoryService::class)->handleStart($bot, $message);\n"
    "    }\n\n"
    "    private function deliverPending"
)
text, count = pattern.subn(replacement, text, count=1)
if count != 1:
    raise SystemExit(f'publication handleHistoryStart replacement count={count}')
publication.write_text(text)

controller = root / 'app/Http/Controllers/GroupChannelWebhookController.php'
text = controller.read_text()
text = text.replace(
    '$historyBotId = $this->historyStartBotId($update);',
    '$historyBotId = $this->historyBotId($update);',
    1,
)
pattern = re.compile(
    r"    private function historyStartBotId\(array \$update\): \?int\n"
    r"    \{.*?\n"
    r"    \}\n\n"
    r"    private function chatId",
    re.S,
)
replacement = '''    private function historyBotId(array $update): ?int
    {
        if (data_get($update, 'message.chat.type') === 'private') {
            $text = trim((string) data_get($update, 'message.text', ''));
            if (preg_match('/^\\/start(?:@[A-Za-z0-9_]+)?\\s+ah_(\\d+)_/', $text, $matches)) {
                return (int) $matches[1];
            }
        }

        if (data_get($update, 'callback_query.message.chat.type') === 'private') {
            $data = (string) data_get($update, 'callback_query.data', '');
            if (preg_match('/^sg_ahr:(\\d+):/', $data, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function chatId'''
text, count = pattern.subn(replacement, text, count=1)
if count != 1:
    raise SystemExit(f'controller history method replacement count={count}')
controller.write_text(text)

legacy_test = root / 'tests/Feature/GroupChannelAlertHistoryDeepLinkTest.php'
text = legacy_test.read_text()
needle = "            'event_at' => CarbonImmutable::parse('2026-08-07T17:31:00Z'),\n            'status' => GroupChannelAlertEvent::STATUS_SENT,"
replace = "            'event_at' => CarbonImmutable::parse('2026-08-07T17:31:00Z'),\n            'started_at' => $cycle,\n            'status' => GroupChannelAlertEvent::STATUS_SENT,"
if needle not in text:
    raise SystemExit('legacy test event marker not found')
text = text.replace(needle, replace, 1)
text = text.replace(
    "&& str_contains((string) ($request['text'] ?? ''), 'ВІДБІЙ ПІД ЧАС ЦІЄЇ ТРИВОГИ')",
    "&& str_contains((string) ($request['text'] ?? ''), '📊 ІСТОРІЯ: ПОВІТРЯНА ТРИВОГА')",
    1,
)
legacy_test.write_text(text)
