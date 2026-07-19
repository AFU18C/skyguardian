<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#061225">
    <title>Вход — SkyGuardian</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #061225;
            --bg-deep: #030b18;
            --panel: rgba(15, 34, 61, .82);
            --panel-border: rgba(126, 163, 215, .2);
            --text: #f7f9ff;
            --muted: #91a3bf;
            --blue: #1565ff;
            --blue-light: #35b8ff;
        }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100svh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 50% 15%, rgba(21, 101, 255, .12), transparent 29rem),
                linear-gradient(180deg, var(--bg-deep), var(--bg));
        }
        .page {
            width: min(100%, 31rem);
            min-height: 100vh;
            min-height: 100svh;
            margin: 0 auto;
            padding: max(2.25rem, env(safe-area-inset-top)) 1.25rem max(1.4rem, env(safe-area-inset-bottom));
            display: flex;
            flex-direction: column;
        }
        .hero { text-align: center; padding-top: 1rem; }
        .logo {
            width: 9.25rem;
            height: 10.15rem;
            margin: 0 auto .8rem;
            filter: drop-shadow(0 1.1rem 2rem rgba(0, 75, 255, .26));
        }
        h1 { margin: 0; font-size: clamp(2.65rem, 12vw, 3.75rem); letter-spacing: -.055em; line-height: 1; }
        .subtitle { margin: 1rem auto 0; max-width: 22rem; color: var(--muted); font-size: 1.08rem; line-height: 1.5; }
        .radar {
            position: relative;
            width: 16rem;
            height: 8.1rem;
            margin: .25rem auto -1.55rem;
            overflow: hidden;
            opacity: .74;
        }
        .radar::before {
            content: "";
            position: absolute;
            width: 15rem;
            height: 15rem;
            left: .5rem;
            top: 1.15rem;
            border: 1px solid rgba(31, 102, 237, .32);
            border-radius: 50%;
            box-shadow: inset 0 0 0 2.25rem transparent, 0 0 0 2.1rem rgba(31,102,237,.14), 0 0 0 4.15rem rgba(31,102,237,.11);
        }
        .radar::after {
            content: "";
            position: absolute;
            left: 50%;
            bottom: 0;
            width: 7.8rem;
            height: 1px;
            transform: rotate(-29deg);
            transform-origin: left center;
            background: linear-gradient(90deg, rgba(42,111,255,.12), #2488ff);
            box-shadow: 7.65rem 0 0 .17rem #2488ff;
        }
        .login-card {
            position: relative;
            z-index: 2;
            padding: 1.75rem 1.15rem 1.5rem;
            text-align: center;
            background: linear-gradient(145deg, rgba(17, 38, 68, .93), rgba(9, 25, 47, .9));
            border: 1px solid var(--panel-border);
            border-radius: 1.45rem;
            box-shadow: 0 1.5rem 4rem rgba(0, 0, 0, .2);
            backdrop-filter: blur(16px);
        }
        .login-card h2 { margin: 0; font-size: 1.75rem; }
        .login-card p { margin: .65rem 0 1.35rem; color: var(--muted); font-size: 1rem; }
        .telegram-button {
            width: 100%;
            min-height: 4.45rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .8rem;
            padding: .9rem 1.1rem;
            color: #fff;
            font-size: 1.18rem;
            font-weight: 700;
            text-decoration: none;
            border: 0;
            border-radius: 1rem;
            background: linear-gradient(100deg, #125bef, #176ff8 55%, #145ce9);
            box-shadow: 0 .85rem 2rem rgba(7, 83, 240, .3), inset 0 1px 0 rgba(255,255,255,.16);
            transition: transform .16s ease, filter .16s ease;
        }
        .telegram-button:hover { filter: brightness(1.07); transform: translateY(-1px); }
        .telegram-button:active { transform: translateY(1px); }
        .telegram-icon {
            width: 2.65rem;
            height: 2.65rem;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            border-radius: 50%;
            background: rgba(63, 190, 255, .7);
        }
        .secure { margin-top: 1.1rem; color: var(--muted); display: flex; justify-content: center; align-items: center; gap: .55rem; }
        .error {
            margin: 0 0 1rem;
            padding: .85rem 1rem;
            color: #ffd8dd;
            background: rgba(201, 45, 67, .18);
            border: 1px solid rgba(255, 104, 125, .35);
            border-radius: .85rem;
            font-size: .92rem;
        }
        .features { display: grid; gap: 1.45rem; margin: 1.85rem .55rem 1.6rem; }
        .feature { display: grid; grid-template-columns: 4.4rem 1fr; gap: 1rem; align-items: center; }
        .feature-icon {
            width: 4.2rem;
            height: 4.2rem;
            display: grid;
            place-items: center;
            color: #2482ff;
            background: rgba(21, 74, 153, .2);
            border-radius: 50%;
        }
        .feature-copy h3 { margin: 0 0 .2rem; font-size: 1.12rem; }
        .feature-copy p { margin: 0; color: var(--muted); line-height: 1.4; }
        footer { margin-top: auto; padding-top: .4rem; color: var(--muted); text-align: center; line-height: 1.45; }
        .not-configured { opacity: .62; pointer-events: none; }
        @media (max-width: 370px) {
            .page { padding-inline: .9rem; }
            .logo { width: 7.7rem; height: 8.5rem; }
            .feature { grid-template-columns: 3.7rem 1fr; gap: .8rem; }
            .feature-icon { width: 3.55rem; height: 3.55rem; }
        }
    </style>
</head>
<body>
@php
    $callbackUrl = route('telegram.auth.callback');
    $origin = request()->getSchemeAndHttpHost();
    $telegramUrl = $telegramBotId
        ? 'https://oauth.telegram.org/auth?'.http_build_query([
            'bot_id' => $telegramBotId,
            'origin' => $origin,
            'return_to' => $callbackUrl,
            'request_access' => 'write',
        ])
        : '#';
@endphp
<div class="page">
    <main>
        <section class="hero">
            <svg class="logo" viewBox="0 0 180 198" aria-label="SkyGuardian">
                <defs>
                    <linearGradient id="shield" x1="18" y1="8" x2="154" y2="179" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#25A4FF"/>
                        <stop offset=".45" stop-color="#1466F3"/>
                        <stop offset="1" stop-color="#0A3BC5"/>
                    </linearGradient>
                </defs>
                <path d="M90 4 166 34v58c0 50-29 83-76 102C43 175 14 142 14 92V34L90 4Z" fill="url(#shield)"/>
                <path d="M90 13 157 39v52c0 44-24 73-67 91-43-18-67-47-67-91V39L90 13Z" fill="none" stroke="rgba(255,255,255,.18)" stroke-width="3"/>
                <path d="m90 48 12 31 28 19-6 10-25-9 2 32 12 10-4 7-19-8-19 8-4-7 12-10 2-32-25 9-6-10 28-19 12-31Z" fill="#fff"/>
            </svg>
            <h1>SkyGuardian</h1>
            <p class="subtitle">Система мониторинга новостей<br>и воздушных тревог</p>
            <div class="radar" aria-hidden="true"></div>
        </section>

        <section class="login-card">
            <h2>Вход в систему</h2>
            <p>Для продолжения войдите через Telegram</p>

            @if ($errors->has('telegram'))
                <div class="error">{{ $errors->first('telegram') }}</div>
            @endif

            <a class="telegram-button {{ $telegramBotId ? '' : 'not-configured' }}" href="{{ $telegramUrl }}">
                <span class="telegram-icon" aria-hidden="true">
                    <svg width="25" height="25" viewBox="0 0 24 24" fill="none"><path d="m20.7 4.2-3 14.1c-.2 1-1 1.2-1.8.8l-4.6-3.4-2.2 2.1c-.2.3-.5.5-.9.5l.3-4.7 8.6-7.8c.4-.3-.1-.5-.6-.2L5.9 12.3l-4.6-1.4c-1-.3-1-1 .2-1.5L19.4 2.5c.8-.3 1.6.2 1.3 1.7Z" fill="white"/></svg>
                </span>
                Войти через Telegram
            </a>

            <div class="secure">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="11" rx="2" stroke="currentColor" stroke-width="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3" stroke="currentColor" stroke-width="2"/></svg>
                Безопасно и быстро
            </div>
        </section>

        <section class="features">
            <article class="feature">
                <div class="feature-icon"><svg width="35" height="35" viewBox="0 0 24 24" fill="none"><path d="M12 3 20 6v6c0 5-3.4 8-8 9.8C7.4 20 4 17 4 12V6l8-3Z" stroke="currentColor" stroke-width="1.8"/><path d="m8.5 12 2.2 2.2 4.8-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <div class="feature-copy"><h3>Безопасный доступ</h3><p>Вход через Telegram обеспечивает надежную защиту вашего аккаунта</p></div>
            </article>
            <article class="feature">
                <div class="feature-icon"><svg width="35" height="35" viewBox="0 0 24 24" fill="none"><path d="m13 2-8 12h6l-1 8 9-13h-6V2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></div>
                <div class="feature-copy"><h3>Быстрый вход</h3><p>Авторизация в один клик без логина и пароля</p></div>
            </article>
            <article class="feature">
                <div class="feature-icon"><svg width="35" height="35" viewBox="0 0 24 24" fill="none"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z" stroke="currentColor" stroke-width="1.8"/><path d="M10 21h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></div>
                <div class="feature-copy"><h3>Всегда на связи</h3><p>Получайте важные уведомления и оставайтесь в курсе событий</p></div>
            </article>
        </section>
    </main>

    <footer>© {{ now()->year }} SkyGuardian<br>Все права защищены</footer>
</div>
</body>
</html>
