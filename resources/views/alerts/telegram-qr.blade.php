<!DOCTYPE html>
<html lang="ru" translate="no">
<head>
<meta name="google" content="notranslate">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Вход в Telegram по QR — SkyGuardian</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#1d2a3c;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{max-width:620px;margin:0 auto;padding:24px 16px 48px}.back{display:inline-block;margin-bottom:18px;color:#246fdb;font-weight:700;text-decoration:none}.card{background:#fff;border:1px solid #e0e7f0;border-radius:20px;padding:24px;box-shadow:0 10px 35px rgba(28,51,84,.08)}h1{margin:0 0 8px;font-size:30px}p{color:#6f7d90;line-height:1.5}.field{margin-top:16px}.field label{display:block;margin-bottom:7px;font-weight:700}.input{width:100%;padding:13px 14px;border:1px solid #d6dfeb;border-radius:11px;font:inherit}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:12px 18px;border:0;border-radius:11px;background:#246fdb;color:#fff;font-weight:800;cursor:pointer;text-decoration:none}.btn.danger{background:#fff0f0;color:#b52f2f}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.flash,.errors,.notice{margin-bottom:16px;padding:13px 15px;border-radius:11px}.flash{background:#eaf8f1;color:#13764b}.errors{background:#fff0f0;color:#a72d2d}.notice{background:#fff7e8;color:#8a5c12}.qr-box{display:grid;place-items:center;margin:22px auto 10px;padding:18px;background:#fff;border:1px solid #dfe7f1;border-radius:18px;max-width:330px}.qr-box canvas,.qr-box img{max-width:100%;height:auto}.steps{margin:18px 0 0;padding-left:22px;color:#46556a;line-height:1.7}.status{text-align:center;font-weight:800;margin-top:14px}.small{font-size:13px;color:#8490a0;word-break:break-all}
</style>
@if($token && in_array($state['status'] ?? null, ['starting','waiting'], true))
<meta http-equiv="refresh" content="3">
@endif
</head>
<body>
<div class="wrap">
<a class="back" href="{{ route('alerts.settings') }}">← Вернуться в настройки тревог</a>
@if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif
<div class="card">
<h1>Вход в Telegram по QR</h1>
<p>QR-код создаётся для отдельного технического аккаунта и не использует сессии других аккаунтов.</p>

@if(!$token)
@if($telegramApis->isEmpty())
<div class="notice">Сначала добавьте Telegram API в настройках бота тревог.</div>
@else
<form method="POST" action="{{ route('alerts.telegram.qr.start') }}">
@csrf
<div class="field"><label>Название аккаунта</label><input class="input" name="label" placeholder="Например: Основной"></div>
<div class="field"><label>Telegram API</label><select class="input" name="telegram_api_credential_id" required>@foreach($telegramApis as $api)<option value="{{ $api->id }}">{{ $api->label }}</option>@endforeach</select></div>
<div class="field"><label>Пароль 2FA, если включён</label><input class="input" type="password" name="telegram_password" autocomplete="current-password" placeholder="Можно оставить пустым"></div>
<div class="actions"><button class="btn" type="submit">Создать QR-код</button></div>
</form>
@endif
@else
@php($status = $state['status'] ?? 'starting')
@if($status === 'waiting' && !empty($state['url']))
<div id="qr" class="qr-box"></div>
<div class="status">Ожидаю сканирование QR-кода</div>
<ol class="steps">
<li>Откройте Telegram на уже авторизованном устройстве.</li>
<li>Перейдите: Настройки → Устройства → Подключить устройство.</li>
<li>Отсканируйте этот QR-код.</li>
</ol>
<p class="small">Страница обновляется автоматически. QR-код действует ограниченное время.</p>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>new QRCode(document.getElementById('qr'),{text:@json($state['url']),width:280,height:280,correctLevel:QRCode.CorrectLevel.M});</script>
@elseif($status === 'starting')
<div class="notice">Telegram создаёт QR-код. Подождите несколько секунд.</div>
@elseif(in_array($status, ['expired','error','password_required'], true))
<div class="errors">{{ $state['message'] ?? 'QR-вход завершился с ошибкой.' }}</div>
@else
<div class="notice">Статус QR-входа: {{ $status }}</div>
@endif
<form method="POST" action="{{ route('alerts.telegram.qr.cancel') }}" class="actions">@csrf @method('DELETE')<button class="btn danger" type="submit">Отменить и создать заново</button></form>
@endif
</div>
</div>
</body>
</html>
