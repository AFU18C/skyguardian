<!DOCTYPE html>
<html lang="ru" translate="no">
<head>
<meta name="google" content="notranslate">
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#081a31">
<title>Источники воздушной тревоги — SkyGuardian</title>
<style>
:root{--nav:#081a31;--nav2:#0a2742;--blue:#246fdb;--blue2:#1c5fc2;--text:#1d2a3c;--muted:#7c8798;--line:#e8edf3;--bg:#f7f9fc;--green:#169b62;--red:#c94242;--amber:#b97810}*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text);background:var(--bg)}a{text-decoration:none;color:inherit}.app{min-height:100vh;display:grid;grid-template-columns:302px minmax(0,1fr)}.sidebar{position:sticky;top:0;height:100vh;padding:28px 20px;background:linear-gradient(180deg,var(--nav),var(--nav2));color:#edf4ff;overflow:auto;z-index:30}.brand{display:flex;align-items:center;gap:12px;margin:0 4px 28px;font-size:21px;font-weight:800}.brand-mark{width:38px;height:44px;display:grid;place-items:center;background:linear-gradient(145deg,#31a5ff,#1359df);clip-path:polygon(50% 0,95% 18%,90% 67%,50% 100%,10% 67%,5% 18%)}.nav-home,.nav-title,.nav-sub{display:flex;align-items:center;gap:13px}.nav-home{padding:17px 16px;border-radius:10px}.nav-home:hover,.nav-sub:hover{background:rgba(255,255,255,.07)}.nav-group{padding:25px 8px 20px;border-bottom:1px solid rgba(255,255,255,.09)}.nav-title{font-size:15px;font-weight:800;text-transform:uppercase}.nav-sub{margin-top:6px;padding:12px 10px 12px 21px;color:#d5dfeb;font-size:16px;border-radius:9px}.nav-sub.active{background:linear-gradient(90deg,#2266cb,#2c74dd);color:#fff}.dot{width:8px;height:8px;border-radius:50%;background:#879ab5}.logout{width:100%;margin-top:24px;padding:13px 16px;border:1px solid rgba(255,255,255,.13);border-radius:10px;background:rgba(255,255,255,.05);color:#e8f0fa;text-align:left}.main{min-width:0;padding:34px 30px}.topbar{display:flex;align-items:center;gap:14px}.menu-btn{display:none;width:44px;height:44px;border:0;border-radius:11px;background:#fff;box-shadow:0 5px 18px rgba(25,49,80,.1);font-size:22px}.heading h1{margin:0;font-size:36px}.heading p{margin:8px 0 0;color:var(--muted);font-size:17px}.content{max-width:1040px;margin-top:28px}.panel{padding:24px;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 25px rgba(35,57,86,.045)}.field{margin-top:16px}.field label{display:block;margin-bottom:7px;font-size:14px;font-weight:700}.input{width:100%;padding:13px 14px;border:1px solid #dbe3ed;border-radius:11px;background:#fbfcfe;color:var(--text);font:inherit;outline:none}.input:focus{border-color:#75a7ec;box-shadow:0 0 0 3px rgba(36,111,219,.1)}.switch-row{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:17px 0;border-bottom:1px solid var(--line)}.switch-copy span{display:block;margin-top:4px;color:var(--muted);font-size:13px}.switch{position:relative;width:52px;height:30px;flex:0 0 auto}.switch input{opacity:0;width:0;height:0}.slider{position:absolute;inset:0;border-radius:30px;background:#cfd7e2;cursor:pointer}.slider:before{content:"";position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#fff;transition:.2s}.switch input:checked+.slider{background:var(--blue)}.switch input:checked+.slider:before{transform:translateX(22px)}.actions{display:flex;justify-content:flex-end;margin-top:20px}.btn{min-height:44px;padding:11px 18px;border:0;border-radius:10px;background:var(--blue);color:#fff;font-weight:750;cursor:pointer}.btn:hover{background:var(--blue2)}.flash,.errors{margin-bottom:18px;padding:13px 15px;border-radius:11px;font-weight:700}.flash{background:#eaf8f1;color:#13764b}.errors{background:#fff0f0;color:#a72d2d}.status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:18px}.status-item{padding:15px;border-radius:13px;background:#f8fafc}.status-item span{display:block;color:var(--muted);font-size:12px}.status-item strong{display:block;margin-top:6px}.overlay{display:none}@media(max-width:800px){.app{display:block}.sidebar{position:fixed;left:0;top:0;width:292px;transform:translateX(-105%);transition:.22s;box-shadow:20px 0 50px rgba(0,0,0,.28)}body.menu-open .sidebar{transform:translateX(0)}.overlay{display:block;position:fixed;inset:0;background:rgba(2,10,23,.52);opacity:0;pointer-events:none;transition:.22s;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}.main{padding:20px 16px}.menu-btn{display:grid;place-items:center}.heading h1{font-size:29px}.status-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="overlay" onclick="document.body.classList.remove('menu-open')"></div>
<div class="app">
<aside class="sidebar">
<div class="brand"><span class="brand-mark">✈</span><span>SkyGuardian</span></div>
<a class="nav-home" href="{{ route('dashboard') }}">⌂ <span>Главная</span></a>
<div class="nav-group"><div class="nav-title">▦ <span>Новости</span></div><a class="nav-sub" href="{{ route('news.sources') }}"><span class="dot"></span>Источники бота</a><a class="nav-sub" href="{{ route('news.settings') }}"><span class="dot"></span>Настройки бота</a></div>
<div class="nav-group"><div class="nav-title">♟ <span>Воздушная тревога</span></div><a class="nav-sub active" href="{{ route('alerts.sources') }}"><span class="dot"></span>Источники бота</a><a class="nav-sub" href="{{ route('alerts.settings') }}"><span class="dot"></span>Настройки бота</a></div>
<div class="nav-group"><div class="nav-title">✹ <span>Общие настройки</span></div><a class="nav-sub" href="{{ route('users.index') }}"><span class="dot"></span>Пользователи</a></div>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">Выйти</button></form>
</aside>
<main class="main">
<div class="topbar"><button class="menu-btn" type="button" onclick="document.body.classList.add('menu-open')">☰</button><div class="heading"><h1>Источники воздушной тревоги</h1><p>Канал получения сообщений и группа публикации</p></div></div>
<div class="content">
@if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif
<section class="panel">
<div class="status-grid">
<div class="status-item"><span>Статус источника</span><strong>{{ $settings->source_status ?: 'Не проверен' }}</strong></div>
<div class="status-item"><span>Статус группы назначения</span><strong>{{ $settings->destination_status ?: 'Не проверена' }}</strong></div>
</div>
<form method="POST" action="{{ route('alerts.sources.update') }}">@csrf @method('PUT')
<div class="field"><label for="source_chat">Канал-источник</label><input class="input" id="source_chat" name="source_chat" value="{{ old('source_chat',$settings->source_chat) }}" placeholder="@channel или Telegram ID"></div>
<div class="field"><label for="destination_chat">Группа назначения</label><input class="input" id="destination_chat" name="destination_chat" value="{{ old('destination_chat',$settings->destination_chat) }}" placeholder="@group или Telegram ID"></div>
<div class="switch-row"><div class="switch-copy"><strong>Автопубликация</strong><span>Новые сообщения автоматически отправляются в группу назначения.</span></div><label class="switch"><input type="checkbox" name="autopublish_enabled" value="1" @checked(old('autopublish_enabled',$settings->autopublish_enabled))><span class="slider"></span></label></div>
<div class="switch-row"><div class="switch-copy"><strong>Обработка текста SkyGuardian</strong><span>Перед публикацией применяется настроенная обработка текста.</span></div><label class="switch"><input type="checkbox" name="text_processing_enabled" value="1" @checked(old('text_processing_enabled',$settings->text_processing_enabled))><span class="slider"></span></label></div>
<div class="actions"><button class="btn" type="submit">Сохранить настройки источника</button></div>
</form>
</section>
</div>
</main>
</div>
</body>
</html>
