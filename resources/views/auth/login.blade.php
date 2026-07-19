<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Вход — SkyGuardian</title>
</head>
<body style="margin:0">
<style>
*{box-sizing:border-box}body{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}.login-page{min-height:100dvh;display:grid;place-items:center;padding:24px;background:linear-gradient(180deg,#0c182b,#132746)}.login-card{width:min(100%,420px);background:#fff;border-radius:24px;padding:26px;box-shadow:0 25px 65px rgba(0,0,0,.25)}.login-logo{text-align:center;margin-bottom:24px}.login-logo strong{display:block;font-size:26px;color:#142037}.login-logo span{color:#728097;font-size:14px}.field{margin-bottom:16px}.field label{display:block;font-weight:700;font-size:14px;margin-bottom:7px;color:#142037}.field input[type=email],.field input[type=password]{width:100%;border:1px solid #dce2eb;border-radius:13px;padding:14px 15px;font-size:16px;outline:none}.field input:focus{border-color:#347ff6;box-shadow:0 0 0 3px rgba(52,127,246,.12)}.remember{display:flex;align-items:center;gap:9px;font-size:14px;color:#728097;margin:4px 0 18px}.login-submit{width:100%;border:0;border-radius:13px;background:#347ff6;color:#fff;padding:14px;font-weight:800;font-size:16px;cursor:pointer}.error{margin:-5px 0 14px;color:#d6384b;font-size:14px}
</style>
<div class="login-page"><div class="login-card"><div class="login-logo"><strong>SkyGuardian</strong><span>Панель управления</span></div><form method="post" action="{{ route('login.store') }}">@csrf<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"></div>@error('email')<div class="error">{{ $message }}</div>@enderror<div class="field"><label for="password">Пароль</label><input id="password" name="password" type="password" required autocomplete="current-password"></div><label class="remember"><input type="checkbox" name="remember" value="1"><span>Запомнить меня</span></label><button class="login-submit" type="submit">Войти</button></form></div></div>
</body>
</html>
