<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каналы Telegram — SkyGuardian</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#08101d;color:#e8eef8;font-family:Inter,system-ui,-apple-system,sans-serif}.layout{min-height:100vh;display:grid;grid-template-columns:256px 1fr}.sidebar{position:sticky;top:0;height:100vh;padding:22px 16px;border-right:1px solid #1d2a3e;background:#0b1423;z-index:30}.brand{display:flex;align-items:center;gap:11px;padding:0 10px 26px;font-size:18px;font-weight:800}.brand-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:#1769ff}.nav-title{margin:17px 12px 8px;color:#5e6e86;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.14em}.nav-item{display:flex;gap:11px;padding:11px 12px;margin:4px 0;border-radius:9px;color:#91a0b6;text-decoration:none;font-size:14px}.nav-item.active{background:#162943;color:#fff}.main{min-width:0}.topbar{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;border-bottom:1px solid #1d2a3e;background:#0b1423}.topbar-left{display:flex;align-items:center;gap:12px}.menu-button{display:none;width:40px;height:40px;border:1px solid #2a3952;border-radius:9px;background:#111d30;color:#fff;font-size:20px}.page-title{font-size:19px;font-weight:750}.logout,.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 13px;border:1px solid #2a3952;border-radius:8px;background:transparent;color:#c7d1df;cursor:pointer;text-decoration:none}.button.primary{background:#1769ff;border-color:#1769ff;color:#fff}.button.success{border-color:#256a50;color:#6ee0aa}.content{padding:28px}.intro{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.intro h1{margin:0 0 7px;font-size:26px}.intro p{margin:0;color:#8190a6}.panel{margin-top:20px;padding:20px;border:1px solid #21304a;border-radius:14px;background:#0f192a}.search{width:100%;padding:11px 12px;margin-bottom:16px;border:1px solid #2a3952;border-radius:9px;background:#0b1423;color:#e6edf7}.row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center;padding:16px 0;border-top:1px solid #1b293d}.row:first-child{border-top:0}.name{font-weight:750}.meta{margin-top:5px;color:#78869b;font-size:12px}.actions{display:flex;gap:8px;flex-wrap:wrap}.flash,.errors{margin-top:16px;padding:12px 14px;border-radius:10px}.flash{border:1px solid #225d46;background:#10291f;color:#7ee0ad}.errors{border:1px solid #66323b;background:#2b151a;color:#ff9aaa}.empty{padding:28px;text-align:center;color:#75849a}.overlay{display:none}@media(max-width:900px){.layout{display:block}.sidebar{position:fixed;left:0;top:0;width:280px;transform:translateX(-100%);transition:.2s}.menu-button{display:grid;place-items:center}body.menu-open .sidebar{transform:translateX(0)}.overlay{display:block;position:fixed;inset:0;background:rgba(0,0,0,.55);opacity:0;pointer-events:none;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}}@media(max-width:560px){.topbar,.content{padding-left:16px;padding-right:16px}.intro{flex-direction:column}.row{grid-template-columns:1fr}.actions{display:grid;grid-template-columns:1fr}.button{width:100%}}
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
                <div><h1>Каналы аккаунта «{{ $telegramAccount->name }}»</h1><p>Каналы и супергруппы подключённого Telegram-аккаунта.</p></div>
                <a class="button" href="{{ route('integrations.index') }}">Назад</a>
            </div>

            @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif

            <div class="panel">
                <input class="search" id="search" placeholder="Поиск канала..." oninput="filterRows()">
                <div id="list">
                    @forelse($dialogs as $dialog)
                        <div class="row" data-name="{{ mb_strtolower($dialog['name'].' '.($dialog['username'] ?? '')) }}">
                            <div>
                                <div class="name">{{ $dialog['name'] }}</div>
                                <div class="meta">{{ $dialog['username'] ? '@'.$dialog['username'].' · ' : '' }}{{ $dialog['peer_id'] }}</div>
                            </div>
                            <div class="actions">
                                @if(in_array($dialog['peer_id'],$selectedPeerIds,true))
                                    <span class="button success">Добавлен</span>
                                @else
                                    <form method="POST" action="{{ route('integrations.telegram.dialogs.store',$telegramAccount) }}">
                                        @csrf
                                        <input type="hidden" name="peer_id" value="{{ $dialog['peer_id'] }}">
                                        <input type="hidden" name="name" value="{{ $dialog['name'] }}">
                                        <input type="hidden" name="username" value="{{ $dialog['username'] }}">
                                        <button class="button success" type="submit">Добавить в источники</button>
                                    </form>
                                @endif
                                <a class="button primary" href="{{ route('integrations.telegram.messages',['telegramAccount'=>$telegramAccount,'peer_id'=>$dialog['peer_id'],'name'=>$dialog['name']]) }}">Сообщения</a>
                            </div>
                        </div>
                    @empty
                        <div class="empty">Каналы и супергруппы не найдены.</div>
                    @endforelse
                </div>
            </div>
        </section>
    </main>
</div>
<script>
function toggleMenu(){document.body.classList.toggle('menu-open')}
function closeMenu(){document.body.classList.remove('menu-open')}
function filterRows(){const q=document.getElementById('search').value.toLowerCase();document.querySelectorAll('.row').forEach(r=>r.style.display=r.dataset.name.includes(q)?'grid':'none')}
</script>
</body>
</html>