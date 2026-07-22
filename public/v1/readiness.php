<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SkyGuardian\Operations\ReadinessService;

header('Content-Type: application/json; charset=utf-8');

$result = (new ReadinessService($paths->storage(), $store))->inspect();
http_response_code(($result['ready'] ?? false) ? 200 : 503);

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
