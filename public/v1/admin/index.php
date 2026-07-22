<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SkyGuardian\Application;

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SkyGuardian <?= htmlspecialchars(Application::VERSION, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="/v1/assets/admin.css">
</head>
<body>
<main class="layout">
<aside class="sidebar">
<h1>SkyGuardian</h1>
<nav>
<a href="#dashboard">Обзор</a>
<a href="#bot">Telegram Bot</a>
<a href="#moderation">Модерация</a>
<a href="#accounts">Техаккаунты</a>
<a href="#channels">Каналы</a>
<a href="#workers">Workers</a>
</nav>
</aside>
<section class="content">
<header><h2>Панель управления</h2><span id="app-status">Загрузка…</span></header>
<div id="dashboard" class="grid"></div>
</section>
</main>
<script src="/v1/assets/admin.js" defer></script>
</body>
</html>
