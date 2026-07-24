<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — SkyGuardian</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#090f1d;color:#e5edf8;font-family:Inter,system-ui,-apple-system,sans-serif}.card{width:min(420px,calc(100% - 32px));padding:32px;border:1px solid #22304a;border-radius:18px;background:#111a2b;box-shadow:0 24px 70px rgba(0,0,0,.35)}.logo{display:flex;align-items:center;gap:12px;margin-bottom:28px}.mark{width:42px;height:42px;display:grid;place-items:center;border-radius:12px;background:#1769ff;color:white;font-weight:800}.title{font-size:22px;font-weight:750}.subtitle{margin-top:3px;color:#8492a8;font-size:13px}label{display:block;margin:16px 0 7px;color:#aebbd0;font-size:13px}input[type=email],input[type=password]{width:100%;padding:12px 14px;border:1px solid #2a3852;border-radius:10px;background:#0c1423;color:#fff;outline:none}input:focus{border-color:#2474ff;box-shadow:0 0 0 3px rgba(36,116,255,.14)}.remember{display:flex;align-items:center;gap:9px;margin:16px 0;color:#9eabc0;font-size:13px}.error{margin-top:8px;color:#ff7a87;font-size:13px}.button{width:100%;padding:12px;border:0;border-radius:10px;background:#1769ff;color:white;font-weight:700;cursor:pointer}.button:hover{background:#2676ff}.footer{margin-top:20px;text-align:center;color:#64748b;font-size:12px}
    </style>
</head>
<body>
<main class="card">
    <div class="logo">
        <div class="mark">SG</div>
        <div><div class="title">SkyGuardian</div><div class="subtitle">Панель управления системой</div></div>
    </div>

    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        @error('email')<div class="error">{{ $message }}</div>@enderror

        <label for="password">Пароль</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        @error('password')<div class="error">{{ $message }}</div>@enderror

        <label class="remember"><input type="checkbox" name="remember" value="1"> Запомнить меня</label>
        <button class="button" type="submit">Войти</button>
    </form>

    <div class="footer">Защищённый доступ SkyGuardian</div>
</main>
</body>
</html>
