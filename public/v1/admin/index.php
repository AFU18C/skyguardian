<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use SkyGuardian\Application;
use SkyGuardian\Http\Csrf;
use SkyGuardian\Http\SessionAuth;

SessionAuth::requireLogin();
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
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
<form method="post" action="/v1/admin/logout.php"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>"><button type="submit">Выйти</button></form>
</aside>
<section class="content">
<header><h2>Панель управления</h2><span id="app-status">Загрузка…</span></header>
<div id="dashboard" class="grid"></div>
<section id="bot" class="panel"><h2>Telegram Bot</h2><div data-module="bot"></div></section>
<section id="moderation" class="panel"><h2>Модерация</h2><div data-module="moderation"></div></section>
<section id="accounts" class="panel"><h2>Техаккаунты</h2><div data-module="accounts"></div></section>
<section id="channels" class="panel"><h2>Каналы</h2><div data-module="channels"></div></section>
<section id="workers" class="panel"><h2>Workers</h2><div data-module="workers"></div></section>
</section>
</main>
<script src="/v1/assets/admin.js" defer></script>
</body>
</html>
