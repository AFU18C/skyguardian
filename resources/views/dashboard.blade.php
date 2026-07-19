@php
    $cpuCores = 1;
    $cpuInfo = @file_get_contents('/proc/cpuinfo');
    if (is_string($cpuInfo) && $cpuInfo !== '') {
        $detectedCores = preg_match_all('/^processor\s*:/m', $cpuInfo);
        $cpuCores = max(1, (int) $detectedCores);
    }

    $load = sys_getloadavg();
    $loadOneMinute = is_array($load) ? (float) ($load[0] ?? 0) : 0;
    $cpuPercent = min(100, max(0, round(($loadOneMinute / $cpuCores) * 100)));

    $memoryPercent = 0;
    $memoryInfo = @file_get_contents('/proc/meminfo');
    if (is_string($memoryInfo) && $memoryInfo !== '') {
        preg_match('/^MemTotal:\s+(\d+)/m', $memoryInfo, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)/m', $memoryInfo, $availableMatch);
        $memoryTotal = (int) ($totalMatch[1] ?? 0);
        $memoryAvailable = (int) ($availableMatch[1] ?? 0);
        if ($memoryTotal > 0) {
            $memoryPercent = min(100, max(0, round((($memoryTotal - $memoryAvailable) / $memoryTotal) * 100)));
        }
    }

    $diskPercent = 0;
    $diskTotal = @disk_total_space('/');
    $diskFree = @disk_free_space('/');
    if (is_numeric($diskTotal) && is_numeric($diskFree) && $diskTotal > 0) {
        $diskPercent = min(100, max(0, round((($diskTotal - $diskFree) / $diskTotal) * 100)));
    }
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#081a31">
    <title>Главная — SkyGuardian</title>
    <style>
        :root{--nav:#081a31;--nav2:#0a2742;--blue:#246fdb;--text:#1d2a3c;--muted:#7c8798;--line:#e8edf3;--bg:#f7f9fc;--white:#fff;--green:#43bd78;--red:#f43f5e;--purple:#764ae8}
        *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text);background:var(--bg)}
        button{font:inherit}a{text-decoration:none;color:inherit}.app{min-height:100vh;display:grid;grid-template-columns:302px minmax(0,1fr)}
        .sidebar{position:sticky;top:0;height:100vh;padding:28px 20px;background:linear-gradient(180deg,var(--nav),var(--nav2));color:#edf4ff;overflow:auto;z-index:30}
        .brand{display:flex;align-items:center;gap:12px;margin:0 4px 28px;font-size:21px;font-weight:800}.brand-mark{width:38px;height:44px;display:grid;place-items:center;background:linear-gradient(145deg,#31a5ff,#1359df);clip-path:polygon(50% 0,95% 18%,90% 67%,50% 100%,10% 67%,5% 18%)}
        .nav-home,.nav-title,.nav-sub{display:flex;align-items:center;gap:13px}.nav-home{padding:17px 16px;border-radius:10px;background:linear-gradient(90deg,#2266cb,#2c74dd);font-weight:700;box-shadow:0 9px 22px rgba(22,97,210,.24)}
        .nav-group{padding:25px 8px 20px;border-bottom:1px solid rgba(255,255,255,.09)}.nav-title{font-size:15px;font-weight:800;text-transform:uppercase}.nav-title .chev{margin-left:auto;color:#aabbd1}.nav-sub{padding:14px 0 0 21px;color:#d5dfeb;font-size:16px}.dot{width:8px;height:8px;border-radius:50%;background:#879ab5}.nav-icon{width:26px;text-align:center;font-size:22px}.news{color:#2584ff}.alarm{color:#ff3f5d}.settings{color:#8b50ff}
        .logout{width:100%;margin-top:24px;padding:13px 16px;border:1px solid rgba(255,255,255,.13);border-radius:10px;background:rgba(255,255,255,.05);color:#e8f0fa;text-align:left;cursor:pointer}
        .main{min-width:0;padding:34px 30px 42px}.topbar{display:flex;align-items:center;gap:14px}.menu-btn{display:none;width:44px;height:44px;border:0;border-radius:11px;background:#fff;box-shadow:0 5px 18px rgba(25,49,80,.1);font-size:22px}.heading h1{margin:0;font-size:36px;letter-spacing:-.035em}.heading p{margin:8px 0 0;color:var(--muted);font-size:17px}
        .content{max-width:920px;margin-top:28px}.metrics{display:grid;gap:20px}.metric-card{min-height:142px;display:grid;grid-template-columns:76px 1fr 24px;gap:18px;align-items:center;padding:24px 26px;background:var(--white);border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 25px rgba(35,57,86,.045)}
        .metric-icon{width:68px;height:68px;display:grid;place-items:center;border-radius:50%;font-size:30px}.metric-icon.blue{color:#1673ec;background:#eaf3ff}.metric-icon.red{color:var(--red);background:#fff0f3}.metric-icon.purple{color:var(--purple);background:#f1edff}.metric-label{font-size:15px;font-weight:800;color:#657185;text-transform:uppercase}.metric-number{margin-top:2px;font-size:35px;font-weight:800;line-height:1}.metric-note{margin-top:9px;color:#8993a2;font-size:15px}.arrow{color:#9ba7b7;font-size:32px;font-weight:300}
        .system-card{margin-top:22px;background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 8px 25px rgba(35,57,86,.045)}.system-head{display:flex;align-items:center;gap:13px;padding:22px 25px;border-bottom:1px solid var(--line);font-weight:800;font-size:18px}.system-head span:first-child{color:#34a37b}.system-row{display:grid;grid-template-columns:minmax(130px,1fr) minmax(130px,1fr) 54px;gap:18px;align-items:center;padding:20px 25px;border-bottom:1px solid var(--line)}.system-row:last-child{border-bottom:0}.system-value{color:#657185;text-align:right;font-weight:700}.progress{height:8px;border-radius:999px;background:#edf1f6;overflow:hidden}.progress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#2d8cff,#52b5ff)}.progress.memory span{background:linear-gradient(90deg,#7a55e8,#a58bff)}.progress.disk span{background:linear-gradient(90deg,#36a879,#63d29c)}
        .protection{margin-top:22px;padding:23px 25px;border:1px solid #dce9ff;border-radius:18px;background:linear-gradient(110deg,#f6f9ff,#fff);box-shadow:0 8px 25px rgba(35,57,86,.035)}.protection-title{display:flex;align-items:center;gap:12px;font-size:18px;font-weight:800}.protection-title span:first-child{color:#2274e9}.protection p{margin:12px 0 0;color:#718096;line-height:1.5}
        .overlay{display:none}
        @media(max-width:800px){.app{display:block}.sidebar{position:fixed;left:0;top:0;width:292px;transform:translateX(-105%);transition:transform .22s ease;box-shadow:20px 0 50px rgba(0,0,0,.28)}body.menu-open .sidebar{transform:translateX(0)}.overlay{display:block;position:fixed;inset:0;background:rgba(2,10,23,.52);opacity:0;pointer-events:none;transition:opacity .22s;z-index:20}body.menu-open .overlay{opacity:1;pointer-events:auto}.main{padding:20px 16px 34px}.menu-btn{display:grid;place-items:center}.heading h1{font-size:30px}.heading p{font-size:15px}.content{margin-top:22px}.metric-card{grid-template-columns:60px 1fr 16px;gap:13px;padding:20px 17px;min-height:126px}.metric-icon{width:56px;height:56px;font-size:25px}.metric-number{font-size:31px}.system-row{grid-template-columns:1fr 52px;gap:10px;padding:18px}.system-row .progress{grid-column:1/-1}.system-head,.protection{padding:20px}.arrow{font-size:26px}}
    </style>
</head>
<body>
<div class="overlay" onclick="document.body.classList.remove('menu-open')"></div>
<div class="app">
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">✈</span><span>SkyGuardian</span></div>
        <a class="nav-home" href="{{ route('dashboard') }}"><span class="nav-icon">⌂</span><span>Главная</span></a>
        <div class="nav-group">
            <div class="nav-title"><span class="nav-icon news">▦</span><span>Новости</span><span class="chev">⌃</span></div>
            <a class="nav-sub" href="{{ route('news.sources') }}"><span class="dot"></span><span>Источники Бота</span></a>
            <a class="nav-sub" href="{{ route('news.settings') }}"><span class="dot"></span><span>Настройки Бота</span></a>
        </div>
        <div class="nav-group">
            <div class="nav-title"><span class="nav-icon alarm">♟</span><span>Воздушная тревога</span><span class="chev">⌄</span></div>
            <a class="nav-sub" href="{{ route('alerts.sources') }}"><span class="dot"></span><span>Источники Бота</span></a>
            <a class="nav-sub" href="{{ route('alerts.settings') }}"><span class="dot"></span><span>Настройки Бота</span></a>
        </div>
        <div class="nav-group">
            <div class="nav-title"><span class="nav-icon settings">✹</span><span>Общие настройки</span><span class="chev">⌄</span></div>
            <a class="nav-sub" href="{{ route('users.index') }}"><span class="dot"></span><span>Пользователи</span></a>
        </div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout" type="submit">Выйти</button></form>
    </aside>

    <main class="main">
        <div class="topbar">
            <button class="menu-btn" type="button" aria-label="Открыть меню" onclick="document.body.classList.add('menu-open')">☰</button>
            <div class="heading"><h1>Главная</h1><p>Добро пожаловать в SkyGuardian</p></div>
        </div>

        <div class="content">
            <section class="metrics">
                <article class="metric-card"><div class="metric-icon blue">▦</div><div><div class="metric-label">Новости</div><div class="metric-number">0</div><div class="metric-note">источников подключено</div></div><div class="arrow">›</div></article>
                <article class="metric-card"><div class="metric-icon red">♟</div><div><div class="metric-label">Воздушная тревога</div><div class="metric-number">0</div><div class="metric-note">источников подключено</div></div><div class="arrow">›</div></article>
                <article class="metric-card"><div class="metric-icon purple">👥</div><div><div class="metric-label">Пользователи</div><div class="metric-number">{{ \App\Models\User::count() }}</div><div class="metric-note">активных пользователей</div></div><div class="arrow">›</div></article>
            </section>

            <section class="system-card">
                <div class="system-head"><span>▣</span><span>Мониторинг сервера</span></div>
                <div class="system-row"><span>Нагрузка CPU</span><div class="progress"><span style="width: {{ $cpuPercent }}%"></span></div><span class="system-value">{{ $cpuPercent }}%</span></div>
                <div class="system-row"><span>Использование памяти</span><div class="progress memory"><span style="width: {{ $memoryPercent }}%"></span></div><span class="system-value">{{ $memoryPercent }}%</span></div>
                <div class="system-row"><span>Использование диска</span><div class="progress disk"><span style="width: {{ $diskPercent }}%"></span></div><span class="system-value">{{ $diskPercent }}%</span></div>
                <div class="system-row"><span>Версия</span><div></div><span class="system-value">v1.0.0</span></div>
            </section>

            <section class="protection"><div class="protection-title"><span>♢</span><span>Надёжная защита неба</span></div><p>Мониторинг новостей и воздушных тревог<br>24/7 в реальном времени</p></section>
        </div>
    </main>
</div>
</body>
</html>
