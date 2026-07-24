<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $section === 'news' ? 'Новости' : 'Воздушная тревога' }} — SkyGuardian</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#09101e;color:#e6edf7;font-family:Inter,system-ui,-apple-system,sans-serif}.layout{min-height:100vh;display:grid;grid-template-columns:250px 1fr}.sidebar{position:sticky;top:0;height:100vh;padding:22px 16px;border-right:1px solid #1f2b40;background:#0d1626;z-index:30}.brand{display:flex;align-items:center;gap:11px;padding:0 10px 24px;font-size:18px;font-weight:800}.brand-mark{width:36px;height:36px;display:grid;place-items:center;border-radius:10px;background:#1769ff}.nav-title{margin:18px 12px 8px;color:#60708a;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em}.nav-item{display:flex;align-items:center;gap:11px;padding:11px 12px;margin:4px 0;border-radius:9px;color:#91a0b7;text-decoration:none;font-size:14px}.nav-item.active{background:#172843;color:#fff}.nav-item:hover{background:#132037;color:#fff}.main{min-width:0}.topbar{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;border-bottom:1px solid #1f2b40;background:#0c1524}.topbar-left{display:flex;align-items:center;gap:12px}.menu-button{display:none;width:40px;height:40px;border:1px solid #2a3952;border-radius:9px;background:#111d30;color:#d7e1ef;font-size:20px;cursor:pointer}.page-title{font-size:20px;font-weight:750}.user{display:flex;align-items:center;gap:12px;color:#9aa8bc;font-size:14px}.logout,.button{padding:9px 13px;border:1px solid #2a3952;border-radius:8px;background:transparent;color:#c7d1df;cursor:pointer}.button.primary{border-color:#1769ff;background:#1769ff;color:#fff}.button.danger{border-color:#54313a;color:#ff8798}.content{padding:28px}.header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:20px}.header h1{margin:0 0 6px;font-size:26px}.header p{margin:0;color:#8190a6}.panel{padding:20px;border:1px solid #22304a;border-radius:14px;background:#101a2b}.form-grid{display:grid;grid-template-columns:1fr 1.5fr auto;gap:12px;align-items:end}.field label{display:block;margin-bottom:7px;color:#8391a7;font-size:12px}.field input{width:100%;padding:11px 12px;border:1px solid #2a3952;border-radius:9px;background:#0b1423;color:#e6edf7;outline:none}.field input:focus{border-color:#1769ff}.flash{margin-bottom:16px;padding:12px 14px;border:1px solid #225d46;border-radius:10px;background:#10291f;color:#7ee0ad}.errors{margin-bottom:16px;padding:12px 14px;border:1px solid #66323b;border-radius:10px;background:#2b151a;color:#ff9aaa}.list{margin-top:18px}.source{padding:16px 0;border-top:1px solid #1c293d}.source:first-child{border-top:0}.source-head{display:flex;align-items:center;justify-content:space-between;gap:16px}.source-title{display:flex;align-items:center;gap:10px;font-weight:700}.dot{width:9px;height:9px;border-radius:50%}.dot.on{background:#39d98a}.dot.off{background:#708096}.meta{margin-top:5px;color:#78869b;font-size:12px}.actions{display:flex;gap:8px;flex-wrap:wrap}.edit{display:grid;grid-template-columns:1fr 1.5fr auto;gap:10px;margin-top:14px}.empty{padding:34px;text-align:center;color:#75849a}.overlay{display:none}
        @media(max-width:900px){.layout{display:block}.sidebar{position:fixed;left:0;top:0;width:280px;transform:translateX(-100%);transition:transform .2s ease;box-shadow:20px 0 50px rgba(0,0,0,.35)}body.menu-open .sidebar{transform:translateX(0)}.menu-button{display:grid;place-items:center}.overlay{display:block;position:fixed;inset:0;background:rgba(0,0,0,.55);opacity:0;pointer-events:none;transition:.2s;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}.form-grid,.edit{grid-template-columns:1fr}.source-head{align-items:flex-start;flex-direction:column}}
        @media(max-width:560px){.topbar,.content{padding-left:16px;padding-right:16px}.topbar{height:64px}.page-title{font-size:17px}.user>span{display:none}.header{flex-direction:column}}
    </style>
</head>
<body>
<div class="overlay" onclick="closeMenu()"></div>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><div class="brand-mark">SG</div>SkyGuardian</div>
        <a class="nav-item" href="{{ route('dashboard') }}">Главная</a>
        <div class="nav-title">Новости</div>
        <a class="nav-item {{ $section === 'news' ? 'active' : '' }}" href="{{ route('news.channels.index') }}">Каналы данных</a>
        <div class="nav-title">Воздушная тревога</div>
        <a class="nav-item {{ $section === 'alerts' ? 'active' : '' }}" href="{{ route('alerts.channels.index') }}">Каналы данных</a>
        <div class="nav-title">Общие настройки</div>
        <a class="nav-item" href="{{ route('integrations.index') }}">Интеграции</a>
        <a class="nav-item" href="#">Управление группой</a>
        <a class="nav-item" href="#">Управление сайтом</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-item" type="submit" style="width:100%;border:0;background:transparent;text-align:left">Выйти</button></form>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-left"><button class="menu-button" type="button" onclick="toggleMenu()">☰</button><div class="page-title">{{ $section === 'news' ? 'Новости' : 'Воздушная тревога' }}</div></div>
            <div class="user"><span>{{ auth()->user()->name }}</span></div>
        </header>

        <section class="content">
            <div class="header"><div><h1>Каналы данных</h1><p>{{ $section === 'news' ? 'Каналы и группы для новостей.' : 'Каналы и группы для воздушной тревоги.' }}</p></div></div>

            @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif

            <div class="panel">
                <form method="POST" action="{{ route('channels.store', $section) }}" class="form-grid">
                    @csrf
                    <div class="field"><label>Название</label><input name="name" value="{{ old('name') }}" placeholder="Название канала или группы" required></div>
                    <div class="field"><label>Ссылка или username</label><input name="identifier" value="{{ old('identifier') }}" placeholder="https://t.me/channel или @channel" required></div>
                    <button class="button primary" type="submit">Добавить канал/группу</button>
                </form>
            </div>

            <div class="panel list">
                @forelse($sources as $source)
                    <details class="source">
                        <summary class="source-head">
                            <div><div class="source-title"><span class="dot {{ $source->is_active ? 'on' : 'off' }}"></span>{{ $source->name }}</div><div class="meta">Telegram · {{ $source->identifier }}</div></div>
                            <div class="actions">
                                <form method="POST" action="{{ route('channels.toggle', $source) }}">@csrf @method('PATCH')<button class="button" type="submit">{{ $source->is_active ? 'Отключить' : 'Включить' }}</button></form>
                                <form method="POST" action="{{ route('channels.destroy', $source) }}" onsubmit="return confirm('Удалить канал или группу?')">@csrf @method('DELETE')<button class="button danger" type="submit">Удалить</button></form>
                            </div>
                        </summary>
                        <form method="POST" action="{{ route('channels.update', $source) }}" class="edit">
                            @csrf @method('PUT')
                            <div class="field"><label>Название</label><input name="name" value="{{ $source->name }}" required></div>
                            <div class="field"><label>Ссылка или username</label><input name="identifier" value="{{ $source->identifier }}" required></div>
                            <button class="button primary" type="submit">Сохранить</button>
                        </form>
                    </details>
                @empty
                    <div class="empty">Каналы и группы ещё не добавлены.</div>
                @endforelse
            </div>
        </section>
    </main>
</div>
<script>function toggleMenu(){document.body.classList.toggle('menu-open')}function closeMenu(){document.body.classList.remove('menu-open')}</script>
</body>
</html>
