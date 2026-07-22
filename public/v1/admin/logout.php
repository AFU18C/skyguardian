<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SkyGuardian\Http\Csrf;
use SkyGuardian\Http\SessionAuth;

SessionAuth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
Csrf::requireValid($_POST['_csrf'] ?? null);
SessionAuth::logout();
header('Location: /v1/admin/login.php', true, 302);
