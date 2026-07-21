<?php
declare(strict_types=1);

$scopeInput = trim((string) ($_GET['scope'] ?? $_POST['scope'] ?? ''));
$scope = in_array($scopeInput, ['news', 'news-settings'], true) ? 'news' : 'alerts';
$storageDir = dirname(__DIR__) . '/storage';
$sessionsDir = $scope === 'news'
    ? $storageDir . '/telegram-news-sessions'
    : $storageDir . '/telegram-sessions';

if (!is_dir($sessionsDir) && !mkdir($sessionsDir, 0770, true) && !is_dir($sessionsDir)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Не удалось подготовить рабочий каталог Telegram.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!chdir($sessionsDir)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Не удалось открыть рабочий каталог Telegram.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/telegram-account-core.php';
