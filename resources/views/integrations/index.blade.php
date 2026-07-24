<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Интеграции — SkyGuardian</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#08101d;color:#e8eef8;font-family:Inter,system-ui,-apple-system,sans-serif}.layout{min-height:100vh;display:grid;grid-template-columns:256px 1fr}.sidebar{position:sticky;top:0;height:100vh;padding:22px 16px;border-right:1px solid #1d2a3e;background:#0b1423;z-index:30}.brand{display:flex;align-items:center;gap:11px;padding:0 10px 26px;font-size:18px;font-weight:800}.brand-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:#1769ff}.nav-title{margin:17px 12px 8px;color:#5e6e86;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.14em}.nav-item{display:flex;gap:11px;padding:11px 12px;margin:4px 0;border-radius:9px;color:#91a0b6;text-decoration:none;font-size:14px}.nav-item.active{background:#162943;color:#fff}.main{min-width:0}.topbar{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;border-bottom:1px solid #1d2a3e;background:#0b1423}.topbar-left{display:flex;align-items:center;gap:12px}.menu-button{display:none;width:40px;height:40px;border:1px solid #2a3952;border-radius:9px;background:#111d30;color:#fff;font-size:20px}.page-title{font-size:19px;font-weight:750}.logout{padding:8px 12px;border:1px solid #2a3952;border-radius:8px;background:transparent;color:#c7d1df}.content{padding:28px}.intro h1{margin:0 0 7px;font-size:26px}.intro p{margin:0;color:#8190a6}.panel{margin-top:20px;padding:20px;border:1px solid #21304a;border-radius:14px;background:#0f192a}.integration-head{display:flex;align-items:center;justify-content:space-between;gap:16px}.integration-name{display:flex;align-items:center;gap:12px;font-weight:750}.icon{width:42px;height:42px;display:grid;place-items:center;border-radius:12px;background:#162943;color:#78a8ff}.state{display:flex;align-items:center;gap:8px;color:#aab6c8;font-size:13px}.dot{width:10px;height:10px;border-radius:50%}.ok{background:#39d98a}.warn{background:#efb446}.checks{margin-top:18px;border-top:1px solid #1b293d}.check{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #1b293d;color:#9caabd}.value-ok{color:#54dda0}.value-bad{color:#ff9b70}.notice{margin-top:18px;padding:14px;border:1px solid #29466f;border-radius:10px;background:#111f34;color:#9db9e6;font-size:13px;line-height:1.55}.overlay{display:none}@media(max-width:900px){.layout{display:block}.sidebar{position:fixed;left:0;top:0;width:280px;transform:translateX(-100%);transition:.2s}.menu-button{display:grid;place-items:center}body.menu-open .sidebar{transform:translateX(0)}.overlay{display:block;position:fixed;inset:0;background:rgba(0,0,0,.55);opacity:0;pointer-events:none;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}}@media(max-width:560px){.topbar,.content{padding-left:16px;padding-right:16px}.page-title{font-size:17px}.integration-head{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<div class="overlay" onclick="closeMenu()"></div>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><div class="brand-mark">SG</div>SkyGuardian</div>
        <div class="nav-title">Управление</div>
        <a class="nav-item" href="{{ route('dashboard') }}">◫ Обзор</a>
        <a class="nav-item" href="{{ route('sources.index') }}">◉ Источники</a>
        <a class="nav-item" href="#">≡ Новости</a>
        <a class="nav-item" href="#">△ Оповещения</a>
        <div class="nav-title">Система</div>
        <a class="nav-item active" href="{{ route('integrations.index') }}">⌁ Интеграции</a>
        <a class="nav-item" href="#">⚙ Настройки</a>
    </aside>
    <main class="main">
        <header class="topbar">
            <div class="topbar-left"><button class="menu-button" onclick="toggleMenu()">☰</button><div class="page-title">Интеграции</div></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout">Выйти</button></form>
        </header>
        <section class="content">
            <div class="intro"><h1>Telegram API</h1><p>Проверка готовности сервера к подключению через MadelineProto.</p></div>
            <div class="panel">
                <div class="integration-head">
                    <div class="integration-name"><div class="icon">TG</div>MadelineProto</div>
                    <div class="state"><span class="dot {{ $madelineInstalled && $requirementsReady ? 'ok' : 'warn' }}"></span>{{ $madelineInstalled ? 'Библиотека установлена' : 'Библиотека не установлена' }}</div>
                </div>
                <div class="checks">
                    <div class="check"><span>Composer-пакет danog/madelineproto</span><strong class="{{ $madelineInstalled ? 'value-ok' : 'value-bad' }}">{{ $madelineInstalled ? 'Готово' : 'Не установлен' }}</strong></div>
                    @foreach($extensions as $extension => $loaded)
                        <div class="check"><span>PHP {{ $extension }}</span><strong class="{{ $loaded ? 'value-ok' : 'value-bad' }}">{{ $loaded ? 'Готово' : 'Отсутствует' }}</strong></div>
                    @endforeach
                </div>
                <div class="notice">После успешной установки следующим этапом будет авторизация Telegram и получение последних 5–10 сообщений без сохранения их в базу данных.</div>
            </div>
        </section>
    </main>
</div>
<script>function toggleMenu(){document.body.classList.toggle('menu-open')}function closeMenu(){document.body.classList.remove('menu-open')}</script>
</body>
</html>
