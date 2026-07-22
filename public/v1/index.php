<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SkyGuardian\Application;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(
    (new Application())->health(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
