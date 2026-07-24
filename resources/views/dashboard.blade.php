<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SkyGuardian — Обзор</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#08101d;color:#e8eef8;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.layout{min-height:100vh;display:grid;grid-template-columns:256px minmax(0,1fr)}.sidebar{position:sticky;top:0;height:100vh;padding:22px 16px;border-right:1px solid #1d2a3e;background:#0b1423;z-index:30}.brand{display:flex;align-items:center;gap:11px;padding:0 10px 26px;font-size:18px;font-weight:800}.brand-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:linear-gradient(145deg,#2378ff,#1152ca);box-shadow:0 10px 25px rgba(23,105,255,.25);font-size:13px}.nav-title{margin:17px 12px 8px;color:#5e6e86;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.14em}.nav-item{display:flex;align-items:center;gap:11px;padding:11px 12px;margin:4px 0;border-radius:9px;color:#91a0b6;text-decoration:none;font-size:14px;transition:.15s}.nav-icon{width:18px;text-align:center;color:#6f86a8}.nav-item.active{background:#162943;color:#fff}.nav-item.active .nav-icon{color:#5d9cff}.nav-item:hover{background:#122039;color:#fff}.main{min-width:0}.topbar{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;border-bottom:1px solid #1d2a3e;background:rgba(11,20,35,.92);backdrop-filter:blur(12px)}.topbar-left{display:flex;align-items:center;gap:12px}.menu-button{display:none;width:40px;height:40px;border:1px solid #2a3952;border-radius:9px;background:#111d30;color:#d7e1ef;font-size:20px;cursor:pointer}.page-title{font-size:19px;font-weight:760}.user{display:flex;align-items:center;gap:12px;color:#9aa9bd;font-size:14px}.avatar{width:34px;height:34px;display:grid;place-items:center;border-radius:50%;background:#172943;color:#79a9ff;font-weight:800}.logout{padding:8px 12px;border:1px solid #2a3952;border-radius:8px;background:transparent;color:#c7d1df;cursor:pointer}.logout:hover{background:#17243a}.content{padding:28px}.intro{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:22px}.intro h1{margin:0 0 6px;font-size:26px}.intro p{margin:0;color:#8190a6}.system-time{color:#718198;font-size:12px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.card{padding:18px;border:1px solid #21304a;border-radius:14px;background:linear-gradient(145deg,#101b2d,#0e1828);box-shadow:0 12px 28px rgba(0,0,0,.12)}.card-top{display:flex;justify-content:space-between;gap:12px}.card-label{color:#8493a8;font-size:13px}.card-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:#162943;color:#73a6ff}.card-value{margin-top:8px;font-size:29px;font-weight:800}.status{display:inline-flex;align-items:center;gap:7px;margin-top:10px;color:#7f8da2;font-size:12px}.dot{width:8px;height:8px;border-radius:50%;background:#efb446;box-shadow:0 0 0 4px rgba(239,180,70,.08)}.panel{margin-top:18px;padding:20px;border:1px solid #21304a;border-radius:14px;background:#0f192a}.panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}.panel-title{font-size:16px;font-weight:750}.badge{padding:5px 8px;border-radius:999px;background:#162943;color:#78a8ff;font-size:11px}.service{border-top:1px solid #1b293d}.service:first-of-type{border-top:0}.service summary{display:grid;grid-template-columns:1fr auto;align-items:center;gap:16px;padding:16px 2px;cursor:pointer;list-style:none}.service summary::-webkit-details-marker{display:none}.service-main{display:flex;align-items:center;gap:12px}.service-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:#131f33;color:#7f94b2}.service-name{font-weight:680}.service-state{width:11px;height:11px;border-radius:50%;background:#efb446;box-shadow:0 0 0 4px rgba(239,180,70,.08)}.service-body{padding:0 2px 16px 52px;color:#74839a;font-size:13px;line-height:1.55}.service-body strong{color:#aebbd0;font-weight:650}.service-chevron{color:#687991;font-size:13px;transition:.2s}.service[open] .service-chevron{transform:rotate(180deg)}.state-wrap{display:flex;align-items:center;gap:12px}.footer-note{margin-top:16px;color:#60718a;font-size:12px}.overlay{display:none}
        @media(max-width:900px){.layout{display:block}.sidebar{position:fixed;left:0;top:0;width:280px;transform:translateX(-100%);transition:transform .2s ease;box-shadow:20px 0 50px rgba(0,0,0,.35)}body.menu-open .sidebar{transform:translateX(0)}.menu-button{display:grid;place-items:center}.overlay{display:block;position:fixed;inset:0;background:rgba(0,0,0,.55);opacity:0;pointer-events:none;transition:.2s;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:560px){.topbar,.content{padding-left:16px;padding-right:16px}.topbar{height:64px}.page-title{font-size:17px}.user>span{display:none}.grid{grid-template-columns:1fr}.intro{align-items:flex-start;flex-direction:column}.service-body{padding-left:2px}}
    </style>
</head>
<body>
<div class="overlay" onclick="closeMenu()"></div>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><div class="brand-mark">SG</div>SkyGuardian</div>
        <div class="nav-title">Управление</div>
        <a class="nav-item active" href="{{ route('dashboard') }}"><span class="nav-icon">◫</span>Обзор</a>
        <a class="nav-item" href="{{ route('sources.index') }}"><span class="nav-icon">◉</span>Источники</a>
        <a class="nav-item" href="#"><span class="nav-icon">≡</span>Новости</a>
        <a class="nav-item" href="#"><span class="nav-icon">△</span>Оповещения</a>
        <div class="nav-title">Система</div>
        <a class="nav-item" href="{{ route('integrations.index') }}"><span class="nav-icon">⌁</span>Интеграции</a>
        <a class="nav-item" href="#"><span class="nav-icon">⚙</span>Настройки</a>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-left"><button class="menu-button" type="button" onclick="toggleMenu()">☰</button><div class="page-title">Обзор системы</div></div>
            <div class="user">
                <div class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
                <span>{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">Выйти</button></form>
            </div>
        </header>

        <section class="content">
            <div class="intro">
                <div><h1>SkyGuardian</h1><p>Центр мониторинга источников, новостей и оповещений.</p></div>
                <div class="system-time">{{ now()->format('d.m.Y H:i') }} · Europe/Kyiv</div>
            </div>

            <div class="grid">
                <div class="card"><div class="card-top"><div class="card-label">Источники</div><div class="card-icon">◉</div></div><div class="card-value">0</div><div class="status"><span class="dot"></span>Не настроены</div></div>
                <div class="card"><div class="card-top"><div class="card-label">Новые сообщения</div><div class="card-icon">≡</div></div><div class="card-value">0</div><div class="status"><span class="dot"></span>Ожидание подключения</div></div>
                <div class="card"><div class="card-top"><div class="card-label">Активные правила</div><div class="card-icon">⌁</div></div><div class="card-value">0</div><div class="status"><span class="dot"></span>Не настроены</div></div>
                <div class="card"><div class="card-top"><div class="card-label">Оповещения</div><div class="card-icon">△</div></div><div class="card-value">0</div><div class="status"><span class="dot"></span>Нет событий</div></div>
            </div>

            <div class="panel">
                <div class="panel-head"><div class="panel-title">Состояние компонентов</div><div class="badge">Начальная настройка</div></div>
                <details class="service"><summary><div class="service-main"><div class="service-icon">TG</div><div class="service-name">Telegram API</div></div><div class="state-wrap"><span class="service-state" title="Не подключено"></span><span class="service-chevron">⌄</span></div></summary><div class="service-body"><strong>Статус:</strong> не подключено. MadelineProto и учётные данные Telegram будут настроены на следующем этапе.</div></details>
                <details class="service"><summary><div class="service-main"><div class="service-icon">NW</div><div class="service-name">Обработчик новостей</div></div><div class="state-wrap"><span class="service-state" title="Не подключено"></span><span class="service-chevron">⌄</span></div></summary><div class="service-body"><strong>Статус:</strong> ожидает подключения источников. Воркер пока не запускается.</div></details>
                <details class="service"><summary><div class="service-main"><div class="service-icon">AL</div><div class="service-name">Система оповещений</div></div><div class="state-wrap"><span class="service-state" title="Не подключено"></span><span class="service-chevron">⌄</span></div></summary><div class="service-body"><strong>Статус:</strong> ожидает настройки правил. Воркер оповещений пока не запускается.</div></details>
            </div>
            <div class="footer-note">По умолчанию отображаются только заголовок и цветной индикатор состояния. Подробности открываются нажатием.</div>
        </section>
    </main>
</div>
<script>function toggleMenu(){document.body.classList.toggle('menu-open')}function closeMenu(){document.body.classList.remove('menu-open')}</script>
</body>
</html>