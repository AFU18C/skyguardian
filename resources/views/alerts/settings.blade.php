<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#081a31">
<title>Налаштування бота тривог — SkyGuardian</title>
<style>
:root{--nav:#081a31;--nav2:#0a2742;--blue:#246fdb;--blue2:#1c5fc2;--text:#1d2a3c;--muted:#7c8798;--line:#e8edf3;--bg:#f7f9fc;--white:#fff;--green:#169b62;--red:#c94242;--amber:#b97810}*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text);background:var(--bg)}a{text-decoration:none;color:inherit}.app{min-height:100vh;display:grid;grid-template-columns:302px minmax(0,1fr)}.sidebar{position:sticky;top:0;height:100vh;padding:28px 20px;background:linear-gradient(180deg,var(--nav),var(--nav2));color:#edf4ff;overflow:auto;z-index:30}.brand{display:flex;align-items:center;gap:12px;margin:0 4px 28px;font-size:21px;font-weight:800}.brand-mark{width:38px;height:44px;display:grid;place-items:center;background:linear-gradient(145deg,#31a5ff,#1359df);clip-path:polygon(50% 0,95% 18%,90% 67%,50% 100%,10% 67%,5% 18%)}.nav-home,.nav-title,.nav-sub{display:flex;align-items:center;gap:13px}.nav-home{padding:17px 16px;border-radius:10px}.nav-home:hover,.nav-sub:hover{background:rgba(255,255,255,.07)}.nav-group{padding:25px 8px 20px;border-bottom:1px solid rgba(255,255,255,.09)}.nav-title{font-size:15px;font-weight:800;text-transform:uppercase}.nav-sub{margin-top:6px;padding:12px 10px 12px 21px;color:#d5dfeb;font-size:16px;border-radius:9px}.nav-sub.active{background:linear-gradient(90deg,#2266cb,#2c74dd);color:#fff}.dot{width:8px;height:8px;border-radius:50%;background:#879ab5}.logout{width:100%;margin-top:24px;padding:13px 16px;border:1px solid rgba(255,255,255,.13);border-radius:10px;background:rgba(255,255,255,.05);color:#e8f0fa;text-align:left}.main{min-width:0;padding:34px 30px}.topbar{display:flex;align-items:center;gap:14px}.menu-btn{display:none;width:44px;height:44px;border:0;border-radius:11px;background:#fff;box-shadow:0 5px 18px rgba(25,49,80,.1);font-size:22px}.heading h1{margin:0;font-size:36px}.heading p{margin:8px 0 0;color:var(--muted);font-size:17px}.content{max-width:1040px;margin-top:28px}.grid{display:grid;grid-template-columns:1fr;gap:16px}.card{padding:0;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 25px rgba(35,57,86,.045);overflow:hidden}.accordion-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:22px 24px;cursor:pointer;list-style:none}.accordion-head::-webkit-details-marker{display:none}.accordion-title{display:flex;align-items:center;gap:14px;min-width:0}.accordion-copy h2{margin:0;font-size:20px}.accordion-copy p{margin:5px 0 0;color:var(--muted);font-size:14px}.card:not([open]) .accordion-copy p{display:none}.accordion-meta{display:flex;align-items:center;gap:12px;flex:0 0 auto}.accordion-arrow{font-size:22px;color:var(--muted);transition:.2s}.card[open] .accordion-arrow{transform:rotate(180deg)}.accordion-body{padding:0 24px 24px;border-top:1px solid var(--line)}.status-icon{width:22px;height:22px;display:inline-grid;place-items:center;border-radius:50%;color:#fff;font-size:13px;font-weight:900;line-height:1}.status-icon.ok{background:var(--green)}.status-icon.warn{background:var(--amber)}.status-icon.error{background:var(--red)}.field{margin-top:15px}.field label{display:block;margin-bottom:7px;font-size:14px;font-weight:700}.input{width:100%;padding:13px 14px;border:1px solid #dbe3ed;border-radius:11px;background:#fbfcfe;color:var(--text);font:inherit;outline:none}.input:focus{border-color:#75a7ec;box-shadow:0 0 0 3px rgba(36,111,219,.1)}.input[readonly]{background:#f3f6fa;color:#526174}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:11px 16px;border:0;border-radius:10px;background:var(--blue);color:#fff;font-weight:750;cursor:pointer}.btn:hover{background:var(--blue2)}.btn.secondary{background:#eef4fd;color:var(--blue)}.btn.danger{background:#fff0f0;color:var(--red)}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}.summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:16px}.summary-item{padding:16px;border-radius:13px;background:#f8fafc}.summary-item span{display:block;color:var(--muted);font-size:12px}.summary-item strong{display:block;margin-top:7px;font-size:14px;word-break:break-word}.flash{margin-bottom:18px;padding:13px 15px;border-radius:11px;background:#eaf8f1;color:#13764b;font-weight:700}.errors{margin-bottom:18px;padding:13px 15px;border-radius:11px;background:#fff0f0;color:#a72d2d}.notice{margin-top:14px;padding:13px 14px;border-radius:11px;background:#fff6e6;color:#8b5d10;font-size:14px}.switch-row{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:15px 0;border-bottom:1px solid var(--line)}.switch{position:relative;width:52px;height:30px;flex:0 0 auto}.switch input{opacity:0;width:0;height:0}.slider{position:absolute;inset:0;border-radius:30px;background:#cfd7e2;cursor:pointer}.slider:before{content:"";position:absolute;width:24px;height:24px;left:3px;top:3px;border-radius:50%;background:#fff;transition:.2s}.switch input:checked+.slider{background:var(--blue)}.switch input:checked+.slider:before{transform:translateX(22px)}.savebar{display:flex;justify-content:flex-end;margin-top:18px}.account-toolbar{display:flex;justify-content:flex-end;padding-top:18px}.account-list{display:grid;gap:10px;margin-top:16px}.account-row{border:1px solid var(--line);border-radius:14px;background:#fbfcfe;overflow:hidden}.account-main{display:grid;grid-template-columns:minmax(150px,1.4fr) minmax(120px,1fr) minmax(130px,1fr) auto;gap:14px;align-items:center;padding:15px 16px;cursor:pointer}.account-name{font-weight:800}.account-meta{font-size:13px;color:var(--muted);word-break:break-word}.account-badge{display:inline-block;margin-left:7px;padding:3px 7px;border-radius:999px;background:#eaf1ff;color:var(--blue);font-size:11px;font-weight:800}.account-panel{display:none;padding:0 16px 16px;border-top:1px solid var(--line)}.account-row.active .account-panel{display:block}.inline-error{margin-top:12px;padding:11px 12px;border-radius:10px;background:#fff0f0;color:#a72d2d;font-size:13px}.add-form{display:none;margin-top:14px;padding:16px;border:1px dashed #b9c7da;border-radius:14px;background:#f8fafc}.add-form.open{display:block}.overlay{display:none}@media(max-width:800px){.app{display:block}.sidebar{position:fixed;left:0;top:0;width:292px;transform:translateX(-105%);transition:.22s;box-shadow:20px 0 50px rgba(0,0,0,.28)}body.menu-open .sidebar{transform:translateX(0)}.overlay{display:block;position:fixed;inset:0;background:rgba(2,10,23,.52);opacity:0;pointer-events:none;transition:.22s;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}.main{padding:20px 16px}.menu-btn{display:grid;place-items:center}.heading h1{font-size:29px}.summary{grid-template-columns:1fr}.accordion-head{padding:19px 18px}.accordion-body{padding:0 18px 20px}.accordion-copy h2{font-size:19px}.account-main{grid-template-columns:1fr auto}.account-main .account-meta:nth-child(2),.account-main .account-meta:nth-child(3){grid-column:1/-1}.account-toolbar{justify-content:stretch}.account-toolbar .btn{width:100%}}
</style>
</head>
<body>
<div class="overlay" onclick="document.body.classList.remove('menu-open')"></div>
<div class="app">
<aside class="sidebar">
<div class="brand"><span class="brand-mark">✈</span><span>SkyGuardian</span></div>
<a class="nav-home" href="{{ route('dashboard') }}">⌂ <span>Головна</span></a>
<div class="nav-group"><div class="nav-title">▦ <span>Новини</span></div><a class="nav-sub" href="{{ route('news.sources') }}"><span class="dot"></span>Джерела бота</a><a class="nav-sub" href="{{ route('news.settings') }}"><span class="dot"></span>Налаштування бота</a></div>
<div class="nav-group"><div class="nav-title">♟ <span>Повітряна тривога</span></div><a class="nav-sub" href="{{ route('alerts.sources') }}"><span class="dot"></span>Джерела бота</a><a class="nav-sub active" href="{{ route('alerts.settings') }}"><span class="dot"></span>Налаштування бота</a></div>
<div class="nav-group"><div class="nav-title">✹ <span>Загальні налаштування</span></div><a class="nav-sub" href="{{ route('users.index') }}"><span class="dot"></span>Користувачі</a></div>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">Вийти</button></form>
</aside>
<main class="main">
<div class="topbar"><button class="menu-btn" type="button" onclick="document.body.classList.add('menu-open')">☰</button><div class="heading"><h1>Налаштування бота тривог</h1><p>Основні параметри Telegram та автопублікації</p></div></div>
<div class="content">
@if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif
<div class="grid">
<details class="card">
<summary class="accordion-head"><div class="accordion-title"><div class="accordion-copy"><h2>Технічні акаунти</h2><p>Підключення та керування акаунтами Telethon</p></div></div><div class="accordion-meta">@if($technicalAccounts->contains('status','error'))<span class="status-icon error">×</span>@elseif($technicalAccounts->contains('status','connected'))<span class="status-icon ok">✓</span>@else<span class="status-icon warn">!</span>@endif<span class="accordion-arrow">⌄</span></div></summary>
<div class="accordion-body">
@if(!$telegramApiConfigured)<div class="notice">Вкажіть API ID та App api_hash у блоці Telegram API.</div>@endif
<div class="account-toolbar"><button class="btn" id="show-add-account" type="button">Додати акаунт</button></div>
<div class="add-form {{ $authorizationPending ? 'open' : '' }}" id="add-account-form">
@if($authorizationPending)
<form method="POST" action="{{ route('alerts.telegram.confirm') }}">@csrf
<div class="field"><label for="telegram_code">Код із Telegram</label><input class="input" id="telegram_code" name="telegram_code" inputmode="numeric" autocomplete="one-time-code" required></div>
@if($passwordRequired)<div class="field"><label for="telegram_password">Пароль 2FA</label><input class="input" id="telegram_password" type="password" name="telegram_password" required></div>@endif
<div class="actions"><button class="btn" type="submit">Підтвердити</button><button class="btn secondary cancel-add" type="button">Скасувати</button></div>
</form>
@else
<form method="POST" action="{{ route('alerts.telegram.send-code') }}">@csrf
<div class="field"><label for="account_label">Назва акаунта</label><input class="input" id="account_label" name="label" value="{{ old('label') }}" placeholder="Наприклад: Основний"></div>
<div class="field"><label for="technical_phone_connect">Номер телефону</label><input class="input" id="technical_phone_connect" name="technical_phone" value="{{ old('technical_phone') }}" placeholder="+380 XX XXX XX XX" required></div>
<div class="actions"><button class="btn" type="submit" @disabled(!$telegramApiConfigured)>Надіслати код</button><button class="btn secondary cancel-add" type="button">Скасувати</button></div>
</form>
@endif
</div>
<div class="account-list">
@forelse($technicalAccounts as $account)
<div class="account-row" data-account-row>
<div class="account-main" data-account-toggle>
<div><span class="account-name">{{ $account->label ?: 'Технічний акаунт' }}</span>@if($account->is_primary)<span class="account-badge">Основний</span>@endif</div>
<div class="account-meta">{{ $account->name ?: 'Ім’я не отримано' }} {{ $account->username ? '@'.$account->username : '' }}</div>
<div class="account-meta">{{ $account->phone ?: 'Номер не вказано' }} · ID {{ $account->telegram_id ?: '—' }}</div>
<div>@if($account->status === 'connected')<span class="status-icon ok">✓</span>@elseif($account->status === 'error')<span class="status-icon error">×</span>@else<span class="status-icon warn">!</span>@endif</div>
</div>
<div class="account-panel">
@if($account->last_error)<div class="inline-error">{{ $account->last_error }}</div>@endif
<form method="POST" action="{{ route('alerts.telegram.update',$account) }}">@csrf @method('PUT')
<div class="field"><label>Назва акаунта</label><input class="input" name="label" value="{{ $account->label }}" required></div>
<div class="switch-row"><strong>Основний акаунт</strong><label class="switch"><input type="checkbox" name="is_primary" value="1" @checked($account->is_primary)><span class="slider"></span></label></div>
<div class="actions"><button class="btn" type="submit">Зберегти</button></div>
</form>
<div class="actions">
<form method="POST" action="{{ route('alerts.telegram.send-code') }}">@csrf<input type="hidden" name="account_id" value="{{ $account->id }}"><input type="hidden" name="label" value="{{ $account->label }}"><input type="hidden" name="technical_phone" value="{{ $account->phone }}"><button class="btn secondary" type="submit">Перепідключити</button></form>
<form method="POST" action="{{ route('alerts.telegram.disconnect',$account) }}">@csrf @method('DELETE')<button class="btn secondary" type="submit">Відключити</button></form>
<form method="POST" action="{{ route('alerts.telegram.destroy',$account) }}" onsubmit="return confirm('Видалити цей технічний акаунт?')">@csrf @method('DELETE')<button class="btn danger" type="submit">Видалити</button></form>
</div>
</div>
</div>
@empty
<div class="notice">Технічні акаунти ще не додані.</div>
@endforelse
</div>
</div>
</details>

<details class="card">
<summary class="accordion-head"><div class="accordion-title"><div class="accordion-copy"><h2>Telegram API</h2><p>Дані з my.telegram.org для підключення Telethon</p></div></div><div class="accordion-meta">@if($telegramApiConfigured)<span class="status-icon ok">✓</span>@else<span class="status-icon warn">!</span>@endif<span class="accordion-arrow">⌄</span></div></summary>
<div class="accordion-body"><form method="POST" action="{{ route('alerts.settings.update') }}">@csrf @method('PUT')
<div class="field"><label for="telegram_api_id">API-ідентифікатор</label><input class="input" id="telegram_api_id" name="telegram_api_id" inputmode="numeric" value="{{ old('telegram_api_id',$settings->telegram_api_id) }}"></div>
@if($settings->telegram_api_hash)@php($maskedApiHash=substr($settings->telegram_api_hash,0,4).str_repeat('•',max(strlen($settings->telegram_api_hash)-8,8)).substr($settings->telegram_api_hash,-4))<div class="field"><label>Збережений App api_hash</label><input class="input" value="{{ $maskedApiHash }}" readonly></div>@endif
<div class="field"><label for="telegram_api_hash">{{ $settings->telegram_api_hash ? 'Новий App api_hash' : 'App api_hash' }}</label><input class="input" id="telegram_api_hash" type="password" name="telegram_api_hash"></div>
<div class="savebar"><button class="btn" type="submit">Зберегти Telegram API</button></div></form></div>
</details>

<details class="card">
<summary class="accordion-head"><div class="accordion-title"><div class="accordion-copy"><h2>Telegram-бот</h2><p>Простий пульт керування</p></div></div><div class="accordion-meta">@if($settings->bot_status === 'running')<span class="status-icon ok">✓</span>@else<span class="status-icon warn">!</span>@endif<span class="accordion-arrow">⌄</span></div></summary>
<div class="accordion-body"><form method="POST" action="{{ route('alerts.settings.update') }}">@csrf @method('PUT')
<div class="field"><label for="bot_token">Токен бота</label><input class="input" id="bot_token" type="password" name="bot_token" placeholder="{{ $settings->bot_token ? 'Токен збережено — введіть новий лише для заміни' : 'Вставте токен від BotFather' }}"></div>
<div class="field"><label for="administrator_telegram_id">Telegram ID адміністратора</label><input class="input" id="administrator_telegram_id" name="administrator_telegram_id" value="{{ old('administrator_telegram_id',$settings->administrator_telegram_id) }}"></div>
<div class="field"><label for="source_chat">Канал-джерело</label><input class="input" id="source_chat" name="source_chat" value="{{ old('source_chat',$settings->source_chat) }}"></div>
<div class="field"><label for="destination_chat">Група призначення</label><input class="input" id="destination_chat" name="destination_chat" value="{{ old('destination_chat',$settings->destination_chat) }}"></div>
<div class="switch-row"><strong>Автопублікація</strong><label class="switch"><input type="checkbox" name="autopublish_enabled" value="1" @checked(old('autopublish_enabled',$settings->autopublish_enabled))><span class="slider"></span></label></div>
<div class="switch-row"><strong>Обробка тексту SkyGuardian</strong><label class="switch"><input type="checkbox" name="text_processing_enabled" value="1" @checked(old('text_processing_enabled',$settings->text_processing_enabled))><span class="slider"></span></label></div>
<div class="savebar"><button class="btn" type="submit">Зберегти налаштування</button></div></form></div>
</details>

<details class="card">
<summary class="accordion-head"><div class="accordion-title"><div class="accordion-copy"><h2>Поточний стан</h2><p>Службова інформація</p></div></div><div class="accordion-meta">@if($settings->last_error)<span class="status-icon error">×</span>@elseif($settings->service_status === 'running')<span class="status-icon ok">✓</span>@else<span class="status-icon warn">!</span>@endif<span class="accordion-arrow">⌄</span></div></summary>
<div class="accordion-body"><div class="summary"><div class="summary-item"><span>Сервіс</span><strong>{{ $settings->service_status ?: 'stopped' }}</strong></div><div class="summary-item"><span>Останнє повідомлення</span><strong>{{ optional($settings->last_received_at)->format('d.m.Y H:i') ?: '—' }}</strong></div><div class="summary-item"><span>Остання публікація</span><strong>{{ optional($settings->last_published_at)->format('d.m.Y H:i') ?: '—' }}</strong></div><div class="summary-item"><span>Остання помилка</span><strong>{{ $settings->last_error ?: 'Немає' }}</strong></div></div></div>
</details>
</div>
</div>
</main>
</div>
<script>
const addForm=document.getElementById('add-account-form');document.getElementById('show-add-account')?.addEventListener('click',()=>addForm.classList.add('open'));document.querySelectorAll('.cancel-add').forEach(button=>button.addEventListener('click',()=>addForm.classList.remove('open')));document.querySelectorAll('[data-account-toggle]').forEach(toggle=>toggle.addEventListener('click',()=>toggle.closest('[data-account-row]').classList.toggle('active')));
</script>
</body></html>
