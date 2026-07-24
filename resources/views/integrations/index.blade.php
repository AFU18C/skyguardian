<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Интеграции — SkyGuardian</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#08101d;color:#e8eef8;font-family:Inter,system-ui,-apple-system,sans-serif}.layout{min-height:100vh;display:grid;grid-template-columns:256px 1fr}.sidebar{position:sticky;top:0;height:100vh;padding:22px 16px;border-right:1px solid #1d2a3e;background:#0b1423;z-index:30}.brand{display:flex;align-items:center;gap:11px;padding:0 10px 26px;font-size:18px;font-weight:800}.brand-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:#1769ff}.nav-title{margin:17px 12px 8px;color:#5e6e86;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.14em}.nav-item{display:flex;gap:11px;padding:11px 12px;margin:4px 0;border-radius:9px;color:#91a0b6;text-decoration:none;font-size:14px}.nav-item.active{background:#162943;color:#fff}.main{min-width:0}.topbar{height:72px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;border-bottom:1px solid #1d2a3e;background:#0b1423}.topbar-left{display:flex;align-items:center;gap:12px}.menu-button{display:none;width:40px;height:40px;border:1px solid #2a3952;border-radius:9px;background:#111d30;color:#fff;font-size:20px}.page-title{font-size:19px;font-weight:750}.logout,.button{padding:9px 13px;border:1px solid #2a3952;border-radius:8px;background:transparent;color:#c7d1df;cursor:pointer}.button.primary{background:#1769ff;border-color:#1769ff;color:#fff}.button.danger{border-color:#5b3038;color:#ff8e9c}.content{padding:28px}.intro{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.intro h1{margin:0 0 7px;font-size:26px}.intro p{margin:0;color:#8190a6}.limit{color:#7f90a8;font-size:13px}.panel{margin-top:20px;padding:20px;border:1px solid #21304a;border-radius:14px;background:#0f192a}.form-grid{display:grid;grid-template-columns:1fr .7fr 1.2fr .8fr 1fr auto;gap:12px;align-items:end}.field label{display:block;margin-bottom:7px;color:#8391a7;font-size:12px}.field input,.field select{width:100%;padding:11px 12px;border:1px solid #2a3952;border-radius:9px;background:#0b1423;color:#e6edf7}.flash,.errors{margin-top:16px;padding:12px 14px;border-radius:10px}.flash{border:1px solid #225d46;background:#10291f;color:#7ee0ad}.errors{border:1px solid #66323b;background:#2b151a;color:#ff9aaa}.account{border-top:1px solid #1b293d}.account:first-child{border-top:0}.account summary{display:flex;justify-content:space-between;gap:15px;align-items:center;padding:16px 0;cursor:pointer;list-style:none}.account summary::-webkit-details-marker{display:none}.account-title{display:flex;align-items:center;gap:11px;font-weight:750}.dot{width:10px;height:10px;border-radius:50%;background:#efb446}.dot.connected{background:#39d98a}.meta{margin-top:5px;color:#78869b;font-size:12px}.badge{padding:5px 8px;border-radius:999px;background:#162943;color:#78a8ff;font-size:11px}.edit{display:grid;grid-template-columns:1fr .7fr 1.2fr .8fr 1fr auto auto;gap:10px;align-items:end;padding:0 0 18px}.empty{padding:28px;text-align:center;color:#75849a}.notice{margin-top:18px;padding:14px;border:1px solid #29466f;border-radius:10px;background:#111f34;color:#9db9e6;font-size:13px;line-height:1.55}.overlay{display:none}@media(max-width:1100px){.form-grid,.edit{grid-template-columns:1fr 1fr}}@media(max-width:900px){.layout{display:block}.sidebar{position:fixed;left:0;top:0;width:280px;transform:translateX(-100%);transition:.2s}.menu-button{display:grid;place-items:center}body.menu-open .sidebar{transform:translateX(0)}.overlay{display:block;position:fixed;inset:0;background:rgba(0,0,0,.55);opacity:0;pointer-events:none;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}}@media(max-width:560px){.topbar,.content{padding-left:16px;padding-right:16px}.intro{flex-direction:column}.form-grid,.edit{grid-template-columns:1fr}.account summary{align-items:flex-start;flex-direction:column}}
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
            <div class="intro"><div><h1>Telegram API и аккаунты</h1><p>До 10 отдельных API, аккаунтов и сессий MadelineProto.</p></div><div class="limit">{{ $accounts->count() }} / {{ $accountsLimit }}</div></div>

            @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif

            <div class="panel">
                <form method="POST" action="{{ route('integrations.telegram.store') }}" class="form-grid">
                    @csrf
                    <div class="field"><label>Название API</label><input name="name" value="{{ old('name') }}" placeholder="Основной аккаунт" required></div>
                    <div class="field"><label>API ID</label><input name="api_id" value="{{ old('api_id') }}" inputmode="numeric" required></div>
                    <div class="field"><label>API Hash</label><input name="api_hash" value="{{ old('api_hash') }}" maxlength="32" required></div>
                    <div class="field"><label>Вход</label><select name="login_method"><option value="phone">Телефон</option><option value="qr">QR-код</option></select></div>
                    <div class="field"><label>Номер телефона</label><input name="phone" value="{{ old('phone') }}" placeholder="+380..."></div>
                    <button class="button primary" type="submit" {{ $accounts->count() >= $accountsLimit ? 'disabled' : '' }}>Добавить</button>
                </form>
            </div>

            <div class="panel">
                @forelse($accounts as $account)
                    <details class="account">
                        <summary>
                            <div><div class="account-title"><span class="dot {{ $account->status === 'connected' ? 'connected' : '' }}"></span>{{ $account->name }}</div><div class="meta">{{ $account->login_method === 'qr' ? 'QR-код' : ($account->phone ?: 'Телефон не указан') }} · отдельная сессия №{{ $account->id }}</div></div>
                            <span class="badge">{{ $account->status === 'connected' ? 'Подключено' : 'Не подключено' }}</span>
                        </summary>
                        <form method="POST" action="{{ route('integrations.telegram.update', $account) }}" class="edit">
                            @csrf @method('PUT')
                            <div class="field"><label>Название</label><input name="name" value="{{ $account->name }}" required></div>
                            <div class="field"><label>API ID</label><input name="api_id" value="{{ $account->api_id }}" required></div>
                            <div class="field"><label>Новый API Hash</label><input name="api_hash" maxlength="32" placeholder="Оставить прежний"></div>
                            <div class="field"><label>Вход</label><select name="login_method"><option value="phone" @selected($account->login_method==='phone')>Телефон</option><option value="qr" @selected($account->login_method==='qr')>QR-код</option></select></div>
                            <div class="field"><label>Телефон</label><input name="phone" value="{{ $account->phone }}"></div>
                            <button class="button primary" type="submit">Сохранить</button>
                        </form>
                        <form method="POST" action="{{ route('integrations.telegram.destroy', $account) }}" onsubmit="return confirm('Удалить API, аккаунт и локальную сессию?')" style="padding-bottom:18px">@csrf @method('DELETE')<button class="button danger" type="submit">Удалить</button></form>
                    </details>
                @empty
                    <div class="empty">Telegram API ещё не добавлены.</div>
                @endforelse
            </div>

            <div class="notice">API ID и API Hash хранятся в базе в зашифрованном виде. Для каждого подключения используется отдельная папка сессии. Следующий этап — запуск авторизации по телефону или QR-коду прямо из этой страницы.</div>
        </section>
    </main>
</div>
<script>function toggleMenu(){document.body.classList.toggle('menu-open')}function closeMenu(){document.body.classList.remove('menu-open')}</script>
</body>
</html>
