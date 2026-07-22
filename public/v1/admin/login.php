<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SkyGuardian\Http\Csrf;
use SkyGuardian\Http\SessionAuth;

SessionAuth::start();
if (SessionAuth::check()) {
    header('Location: /v1/admin/', true, 302);
    exit;
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
    if (SessionAuth::attempt($authService, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: /v1/admin/', true, 302);
        exit;
    }
    $error = 'Неверные данные или слишком много попыток.';
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход — SkyGuardian</title><link rel="stylesheet" href="/v1/assets/admin.css"></head><body><main class="login"><form method="post" class="card"><h1>SkyGuardian</h1><?php if ($error): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p><?php endif; ?><input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>"><label>Email<input type="email" name="email" autocomplete="username" required></label><label>Пароль<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Войти</button></form></main></body></html>
