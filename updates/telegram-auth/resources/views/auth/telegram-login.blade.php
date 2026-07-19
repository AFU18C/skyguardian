<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#07172b">
    <title>Вход — SkyGuardian</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #061225;
            --bg-soft: #0a1d35;
            --panel: rgba(13, 34, 61, .92);
            --panel-border: rgba(123, 165, 224, .18);
            --text: #f7f9ff;
            --muted: #94a7c3;
            --blue: #1768f2;
            --blue-light: #2f8cff;
            --danger: #ff8494;
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
                radial-gradient(circle at 50% -8%, rgba(29, 112, 255, .24), transparent 28rem),
                radial-gradient(circle at 12% 72%, rgba(27, 83, 170, .16), transparent 24rem),
                linear-gradient(180deg, #030a16 0%, var(--bg) 62%, #07182b 100%);
        }

        .page {
            width: min(100%, 31rem);
            min-height: 100vh;
            min-height: 100svh;
            margin: 0 auto;
            padding: max(2rem, env(safe-area-inset-top)) 1.2rem max(1.5rem, env(safe-area-inset-bottom));
            display: flex;
            flex-direction: column;
        }

        .brand {
            text-align: center;
            padding: .6rem 0 1.6rem;
        }

        .logo {
            width: 7.8rem;
            height: 8.55rem;
            margin: 0 auto .65rem;
            filter: drop-shadow(0 1rem 2rem rgba(0, 88, 255, .25));
        }

        h1 {
            margin: 0;
            font-size: clamp(2.55rem, 11vw, 3.45rem);
            line-height: 1;
            letter-spacing: -.055em;
        }

        .brand p {
            margin: .85rem auto 0;
            max-width: 22rem;
            color: var(--muted);
            font-size: 1.02rem;
            line-height: 1.5;
        }

        .card {
            padding: 1.55rem 1.15rem 1.25rem;
            background: linear-gradient(145deg, rgba(16, 39, 70, .96), rgba(8, 24, 45, .94));
            border: 1px solid var(--panel-border);
            border-radius: 1.45rem;
            box-shadow: 0 1.6rem 4rem rgba(0, 0, 0, .28), inset 0 1px 0 rgba(255,255,255,.025);
            backdrop-filter: blur(18px);
        }

        .card h2 {
            margin: 0;
            text-align: center;
            font-size: 1.7rem;
        }

        .card-subtitle {
            margin: .55rem 0 1.35rem;
            text-align: center;
            color: var(--muted);
            line-height: 1.45;
        }

        .error {
            margin: 0 0 1rem;
            padding: .8rem .9rem;
            color: #ffe3e7;
            background: rgba(194, 47, 69, .2);
            border: 1px solid rgba(255, 109, 132, .32);
            border-radius: .85rem;
            font-size: .92rem;
        }

        .field { margin-bottom: 1rem; text-align: left; }
        .field label {
            display: block;
            margin: 0 0 .48rem .1rem;
            color: #dce7f8;
            font-size: .92rem;
            font-weight: 650;
        }

        .input-wrap { position: relative; }
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6f8eb8;
            pointer-events: none;
        }

        input[type="email"], input[type="password"] {
            width: 100%;
            height: 3.65rem;
            padding: 0 1rem 0 3rem;
            color: var(--text);
            font: inherit;
            background: rgba(4, 16, 31, .72);
            border: 1px solid rgba(115, 151, 201, .2);
            border-radius: .95rem;
            outline: none;
            transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
        }

        input::placeholder { color: #657996; }
        input:focus {
            background: rgba(5, 20, 39, .92);
            border-color: rgba(47, 140, 255, .75);
            box-shadow: 0 0 0 .22rem rgba(30, 111, 238, .13);
        }

        .options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin: .15rem 0 1.15rem;
            color: var(--muted);
            font-size: .92rem;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            cursor: pointer;
        }

        .remember input {
            width: 1.05rem;
            height: 1.05rem;
            accent-color: var(--blue);
        }

        .submit {
            width: 100%;
            min-height: 3.95rem;
            border: 0;
            border-radius: 1rem;
            color: #fff;
            font: inherit;
            font-size: 1.08rem;
            font-weight: 750;
            cursor: pointer;
            background: linear-gradient(100deg, #135de9, var(--blue) 55%, #195ee4);
            box-shadow: 0 .9rem 2rem rgba(9, 79, 222, .28), inset 0 1px 0 rgba(255,255,255,.16);
            transition: transform .16s ease, filter .16s ease;
        }

        .submit:hover { filter: brightness(1.07); transform: translateY(-1px); }
        .submit:active { transform: translateY(1px); }

        .secure {
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            color: var(--muted);
            font-size: .9rem;
        }

        .status {
            margin-top: 1.5rem;
            padding: 1rem 1.1rem;
            display: flex;
            align-items: center;
            gap: .8rem;
            color: #b7c7dc;
            background: rgba(8, 28, 50, .58);
            border: 1px solid rgba(104, 143, 196, .13);
            border-radius: 1rem;
        }

        .status-dot {
            width: .68rem;
            height: .68rem;
            flex: 0 0 auto;
            border-radius: 50%;
            background: #35cf86;
            box-shadow: 0 0 0 .32rem rgba(53, 207, 134, .1);
        }

        footer {
            margin-top: auto;
            padding-top: 1.5rem;
            text-align: center;
            color: var(--muted);
            font-size: .88rem;
            line-height: 1.45;
        }

        @media (max-width: 370px) {
            .page { padding-inline: .9rem; }
            .logo { width: 6.9rem; height: 7.55rem; }
            .card { padding-inline: .95rem; }
        }
    </style>
</head>
<body>
<div class="page">
    <main>
        <section class="brand">
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
            <p>Система мониторинга новостей и воздушных тревог</p>
        </section>

        <section class="card">
            <h2>Вход в систему</h2>
            <p class="card-subtitle">Введите данные учётной записи администратора</p>

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <div class="field">
                    <label for="email">Email</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M4 6h16v12H4z" stroke="currentColor" stroke-width="1.8"/><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                        </span>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="admin@example.com" autocomplete="email" inputmode="email" required autofocus>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Пароль</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 10V7a4 4 0 1 1 8 0v3" stroke="currentColor" stroke-width="1.8"/></svg>
                        </span>
                        <input id="password" name="password" type="password" placeholder="Введите пароль" autocomplete="current-password" required>
                    </div>
                </div>

                <div class="options">
                    <label class="remember">
                        <input type="checkbox" name="remember" value="1">
                        <span>Запомнить меня</span>
                    </label>
                </div>

                <button class="submit" type="submit">Войти</button>
            </form>

            <div class="secure">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3 20 6v6c0 5-3.4 8-8 9.8C7.4 20 4 17 4 12V6l8-3Z" stroke="currentColor" stroke-width="1.8"/><path d="m8.5 12 2.2 2.2 4.8-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Защищённый доступ к панели управления
            </div>
        </section>

        <div class="status">
            <span class="status-dot"></span>
            <span>Система SkyGuardian работает в штатном режиме</span>
        </div>
    </main>

    <footer>SkyGuardian · Надёжная защита неба</footer>
</div>
</body>
</html>
