<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SkyGuardian</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#09101e;color:#e6edf7;font-family:Inter,system-ui,-apple-system,sans-serif}.layout{min-height:100vh;display:grid;grid-template-columns:250px 1fr}.sidebar{padding:22px 16px;border-right:1px solid #1f2b40;background:#0d1626}.brand{display:flex;align-items:center;gap:11px;padding:0 10px 24px;font-size:18px;font-weight:800}.brand-mark{width:36px;height:36px;display:grid;place-items:center;border-radius:10px;background:#1769ff}.nav-title{margin:18px 12px 8px;color:#60708a;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em}.nav-item{display:flex;align-items:center;gap:11px;padding:11px 12px;margin:4px 0;border-radius:9px;color:#91a0b7;text-decoration:none;font-size:14px}.nav-item.active{background:#172843;color:#fff}.nav-item:hover{background:#132037;color:#fff}.main{min-width:0}.topbar{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;border-bottom:1px solid #1f2b40;background:#0c1524}.page-title{font-size:20px;font-weight:750}.user{display:flex;align-items:center;gap:12px;color:#9aa8bc;font-size:14px}.logout{padding:8px 12px;border:1px solid #2a3952;border-radius:8px;background:transparent;color:#c7d1df;cursor:pointer}.content{padding:28px}.intro{margin-bottom:22px}.intro h1{margin:0 0 6px;font-size:26px}.intro p{margin:0;color:#8190a6}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.card{padding:18px;border:1px solid #22304a;border-radius:14px;background:#101a2b}.card-label{color:#8391a7;font-size:13px}.card-value{margin-top:10px;font-size:28px;font-weight:800}.status{display:inline-flex;align-items:center;gap:7px;margin-top:12px;color:#7f8da2;font-size:12px}.dot{width:8px;height:8px;border-radius:50%;background:#efb446}.panel{margin-top:18px;padding:20px;border:1px solid #22304a;border-radius:14px;background:#101a2b}.panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}.panel-title{font-size:16px;font-weight:700}.badge{padding:5px 8px;border-radius:999px;background:#172843;color:#78a8ff;font-size:11px}.service{display:grid;grid-template-columns:1fr auto;align-items:center;padding:14px 0;border-top:1px solid #1c293d}.service:first-of-type{border-top:0}.service-name{font-weight:650}.service-note{margin-top:4px;color:#74839a;font-size:12px}.pending{color:#efb446;font-size:13px}@media(max-width:900px){.layout{grid-template-columns:1fr}.sidebar{display:none}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.topbar,.content{padding-left:16px;padding-right:16px}.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><div class="brand-mark">SG</div>SkyGuardian</div>
        <div class="nav-title">Управление</div>
        <a class="nav-item active" href="{{ route('dashboard') }}">Обзор</a>
        <a class="nav-item" href="#">Источники</a>
        <a class="nav-item" href="#">Новости</a>
        <a class="nav-item" href="#">Оповещения</a>
        <div class="nav-title">Система</div>
        <a class="nav-item" href="#">Интеграции</a>
        <a class="nav-item" href="#">Настройки</a>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="page-title">Обзор системы</div>
            <div class="user">
                <span>{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">Выйти</button></form>
            </div>
        </header>

        <section class="content">
            <div class="intro"><h1>SkyGuardian</h1><p>Базовая панель управления готова. Подключения будут добавляться по этапам.</p></div>

            <div class="grid">
                <div class="card"><div class="card-label">Источники</div><div class="card-value">0</div><div class="status"><span class="dot"></span>Не настроены</div></div>
                <div class="card"><div class="card-label">Новые сообщения</div><div class="card-value">0</div><div class="status"><span class="dot"></span>Ожидание подключения</div></div>
                <div class="card"><div class="card-label">Активные правила</div><div class="card-value">0</div><div class="status"><span class="dot"></span>Не настроены</div></div>
                <div class="card"><div class="card-label">Оповещения</div><div class="card-value">0</div><div class="status"><span class="dot"></span>Нет событий</div></div>
            </div>

            <div class="panel">
                <div class="panel-head"><div class="panel-title">Состояние компонентов</div><div class="badge">Начальная настройка</div></div>
                <div class="service"><div><div class="service-name">Telegram API</div><div class="service-note">MadelineProto ещё не подключён</div></div><div class="pending">Ожидает</div></div>
                <div class="service"><div><div class="service-name">Обработчик новостей</div><div class="service-note">Воркер будет создан после подключения источников</div></div><div class="pending">Ожидает</div></div>
                <div class="service"><div><div class="service-name">Система оповещений</div><div class="service-note">Воркер будет создан после правил фильтрации</div></div><div class="pending">Ожидает</div></div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
