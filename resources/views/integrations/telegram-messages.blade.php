<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сообщения Telegram — SkyGuardian</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#08101d;color:#e8eef8;font-family:Inter,system-ui,-apple-system,sans-serif}.layout{min-height:100vh;display:grid;grid-template-columns:256px 1fr}.sidebar{position:sticky;top:0;height:100vh;padding:22px 16px;border-right:1px solid #1d2a3e;background:#0b1423;z-index:30}.brand{display:flex;align-items:center;gap:11px;padding:0 10px 26px;font-size:18px;font-weight:800}.brand-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:#1769ff}.nav-title{margin:17px 12px 8px;color:#5e6e86;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.14em}.nav-item{display:flex;gap:11px;padding:11px 12px;margin:4px 0;border-radius:9px;color:#91a0b6;text-decoration:none;font-size:14px}.nav-item.active{background:#162943;color:#fff}.main{min-width:0}.topbar{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;border-bottom:1px solid #1d2a3e;background:#0b1423}.topbar-left{display:flex;align-items:center;gap:12px}.menu-button{display:none;width:40px;height:40px;border:1px solid #2a3952;border-radius:9px;background:#111d30;color:#fff;font-size:20px}.page-title{font-size:19px;font-weight:750}.logout,.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 13px;border:1px solid #2a3952;border-radius:8px;background:transparent;color:#c7d1df;cursor:pointer;text-decoration:none}.content{padding:28px}.intro{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.intro h1{margin:0 0 7px;font-size:26px}.intro p{margin:0;color:#8190a6}.panel{margin-top:20px;padding:20px;border:1px solid #21304a;border-radius:14px;background:#0f192a}.message{padding:16px 0;border-top:1px solid #1b293d}.message:first-child{border-top:0}.meta{display:flex;justify-content:space-between;gap:10px;margin-bottom:9px;color:#78869b;font-size:12px}.text{white-space:pre-wrap;line-height:1.55;overflow-wrap:anywhere}.media{margin-top:9px;color:#78a8ff;font-size:12px}.empty{padding:28px;text-align:center;color:#75849a}.overlay{display:none}@media(max-width:900px){.layout{display:block}.sidebar{position:fixed;left:0;top:0;width:280px;transform:translateX(-100%);transition:.2s}.menu-button{display:grid;place-items:center}body.menu-open .sidebar{transform:translateX(0)}.overlay{display:block;position:fixed;inset:0;background:rgba(0,0,0,.55);opacity:0;pointer-events:none;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}}@media(max-width:560px){.topbar,.content{padding-left:16px;padding-right:16px}.intro{flex-direction:column}.button{width:100%}.meta{flex-direction:column}}
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
            <div class="topbar-left"><button class="menu-button" type="button" onclick="toggleMenu()">☰</button><div class="page-title">Интеграции</div></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">Выйти</button></form>
        </header>

        <section class="content">
            <div class="intro">
                <div><h1>{{ $channelName }}</h1><p>Последние сообщения через аккаунт «{{ $telegramAccount->name }}».</p></div>
                <a class="button" href="{{ route('integrations.telegram.dialogs',$telegramAccount) }}">Назад</a>
            </div>

            <div class="panel">
                @forelse($messages as $message)
                    <article class="message">
                        <div class="meta"><span>Сообщение №{{ $message['id'] }}</span><span>{{ $message['date'] }}</span></div>
                        <div class="text">{{ $message['text'] !== '' ? $message['text'] : 'Текст отсутствует' }}</div>
                        @if($message['has_media'])<div class="media">Есть вложение или медиафайл</div>@endif
                    </article>
                @empty
                    <div class="empty">Сообщения не найдены.</div>
                @endforelse
            </div>
        </section>
    </main>
</div>
<script>function toggleMenu(){document.body.classList.toggle('menu-open')}function closeMenu(){document.body.classList.remove('menu-open')}</script>
</body>
</html>