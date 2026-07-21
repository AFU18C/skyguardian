#!/usr/bin/env php
<?php
declare(strict_types=1);

$target = rtrim((string) ($argv[1] ?? ''), '/');
if ($target === '' || !is_dir($target)) {
    fwrite(STDERR, "Invalid deployment target\n");
    exit(1);
}

$replaceOnce = static function (string $file, string $search, string $replace, string $label): void {
    $content = file_get_contents($file);
    if ($content === false) throw new RuntimeException('Cannot read ' . $file);
    if (str_contains($content, $replace)) return;
    if (!str_contains($content, $search)) throw new RuntimeException('Patch anchor not found: ' . $label);
    $updated = str_replace($search, $replace, $content, $count);
    if ($count !== 1) throw new RuntimeException('Unexpected patch count for ' . $label . ': ' . $count);
    if (file_put_contents($file, $updated) === false) throw new RuntimeException('Cannot write ' . $file);
};

$index = $target . '/public/index.php';
$dataChannel = $target . '/public/data-channel.php';
$dataChannelJs = $target . '/public/assets/data-channel.js';
$worker = $target . '/bin/data-channel-worker.php';

$indexContent = file_get_contents($index);
if ($indexContent === false) throw new RuntimeException('Cannot read index.php');
$indexContent = str_replace('<option value="text_without_links">Только текст без ссылок</option>', '', $indexContent);
if (!str_contains($indexContent, 'site-theme.css')) {
    $indexContent = str_replace('</head>', '<link rel="stylesheet" href="/assets/site-theme.css?v=1"></head>', $indexContent);
}
if (!str_contains($indexContent, 'site-theme.js')) {
    $indexContent = str_replace('</body>', '<script src="/assets/site-theme.js?v=1"></script></body>', $indexContent);
}
file_put_contents($index, $indexContent);

$frequencyField = '<label class="full"><span>Частота проверки *</span><div class="frequency-control"><input name="check_frequency" type="number" inputmode="numeric" min="3" max="86400" step="1" placeholder="Введите частоту" required data-frequency-value><select name="check_frequency_unit" aria-label="Единица частоты проверки" required data-frequency-unit><option value="seconds">Секунды</option><option value="hours">Часы</option></select></div></label>';
$fetchField = $frequencyField . "\n            " . '<label class="full"><span>Сообщений за одну проверку *</span><input name="fetch_limit" type="number" inputmode="numeric" min="1" max="50" step="1" value="10" required><small class="form-hint">От 1 до 50. Для частой проверки обычно достаточно 5–10 сообщений.</small></label>';
$replaceOnce($index, $frequencyField, $fetchField, 'index fetch_limit field');

$replaceOnce(
    $dataChannel,
    "    \$processingStart = trim((string) (\$_POST['processing_start'] ?? 'new'));",
    "    \$processingStart = trim((string) (\$_POST['processing_start'] ?? 'new'));\n    \$fetchLimit = (int) (\$_POST['fetch_limit'] ?? 10);",
    'data-channel fetch limit input'
);
$replaceOnce(
    $dataChannel,
    "    if (!in_array(\$processingStart, ['new', 'last_5', 'last_10', 'last_20'], true)) throw new InvalidArgumentException('Выберите начало обработки.');",
    "    if (!in_array(\$processingStart, ['new', 'last_5', 'last_10', 'last_20'], true)) throw new InvalidArgumentException('Выберите начало обработки.');\n    if (\$fetchLimit < 1 || \$fetchLimit > 50) throw new InvalidArgumentException('Количество сообщений за одну проверку должно быть от 1 до 50.');",
    'data-channel fetch limit validation'
);
$replaceOnce(
    $dataChannel,
    "        'processing_start' => \$processingStart,",
    "        'processing_start' => \$processingStart,\n        'fetch_limit' => \$fetchLimit,",
    'data-channel fetch limit storage'
);

$replaceOnce(
    $dataChannelJs,
    "    if (saveButton) saveButton.textContent = 'Добавить';",
    "    if (form.elements.fetch_limit) form.elements.fetch_limit.value = '10';\n    if (saveButton) saveButton.textContent = 'Добавить';",
    'data-channel js default fetch limit'
);
$replaceOnce(
    $dataChannelJs,
    "    form.elements.check_frequency_unit.value = channel.check_frequency_unit;",
    "    form.elements.check_frequency_unit.value = channel.check_frequency_unit;\n    if (form.elements.fetch_limit) form.elements.fetch_limit.value = channel.fetch_limit || 10;",
    'data-channel js edit fetch limit'
);
$replaceOnce(
    $dataChannelJs,
    "      check_frequency_unit: data.check_frequency_unit || 'seconds',",
    "      check_frequency_unit: data.check_frequency_unit || 'seconds',\n      fetch_limit: data.fetch_limit || '10',",
    'data-channel js submit fetch limit'
);
$replaceOnce(
    $dataChannelJs,
    "        channel.check_frequency + ' ' + unit + ' · ' +",
    "        channel.check_frequency + ' ' + unit + ' · до ' + (channel.fetch_limit || 10) + ' сообщ. · ' +",
    'data-channel js card fetch limit'
);

$replaceOnce(
    $worker,
    "                limit: 50,",
    "                limit: max(1, min(50, (int) (\$channel['fetch_limit'] ?? 10))),",
    'worker configurable fetch limit'
);

echo "Runtime patches applied\n";
