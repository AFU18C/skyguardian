@php
    $status = $account->status;
    $isConnected = $status === 'connected';
    $awaitingCode = $status === 'awaiting_code';
    $awaitingPassword = $status === 'awaiting_password';
    $awaitingQr = $status === 'awaiting_qr';
    $qrUrl = data_get($account->auth_data, 'qr_url');
    $qrActive = $awaitingQr && $qrUrl && $account->auth_expires_at?->isFuture();
    $context = 'account-'.$account->id;
@endphp

<section id="account-connection-{{ $account->id }}" class="sg-connection-panel" data-connection-panel>
    <div class="sg-section-heading">
        <div>
            <p class="sg-eyebrow">Следующий шаг</p>
            <h3>Подключение Telegram</h3>
            <p>После сохранения аккаунта завершите авторизацию здесь, не закрывая форму.</p>
        </div>
        <x-status-badge :status="$account->status" />
    </div>

    @if ($isConnected)
        <div class="sg-auth-success">
            <strong>Telegram успешно подключён</strong>
            <span>{{ $account->username ? '@'.$account->username : 'Сессия сохранена и готова к работе.' }}</span>
        </div>
    @endif

    @if ($account->auth_method === 'phone')
        @if ($awaitingPassword)
            <form method="POST" action="{{ route('admin.telegram.accounts.password', $account) }}" class="sg-auth-step sg-auth-step-active">
                @csrf
                <input type="hidden" name="form_context" value="{{ $context }}">
                <div>
                    <span class="sg-auth-step-number">3</span>
                    <h4>Пароль 2FA</h4>
                    <p>На аккаунте включена двухэтапная защита. Введите облачный пароль Telegram.</p>
                </div>
                <label class="sg-field">
                    <span>Пароль 2FA</span>
                    <input type="password" name="password" required autocomplete="current-password" autofocus>
                    @if (old('form_context') === $context) @error('password')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
                </label>
                <button class="sg-button sg-button-primary" type="submit" data-submit-button>Подтвердить пароль</button>
            </form>
        @elseif ($awaitingCode)
            <form method="POST" action="{{ route('admin.telegram.accounts.sign-in', $account) }}" class="sg-auth-step sg-auth-step-active">
                @csrf
                <input type="hidden" name="form_context" value="{{ $context }}">
                <div>
                    <span class="sg-auth-step-number">2</span>
                    <h4>Введите код Telegram</h4>
                    <p>Код уже отправлен на подключённый номер. После подтверждения система проверит наличие 2FA.</p>
                </div>
                <label class="sg-field">
                    <span>Код авторизации</span>
                    <input type="text" name="code" maxlength="16" required autocomplete="one-time-code" inputmode="numeric" autofocus>
                    @if (old('form_context') === $context) @error('code')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
                </label>
                <div class="sg-auth-actions">
                    <button class="sg-button sg-button-primary" type="submit" data-submit-button>Подтвердить код</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.telegram.accounts.send-code', $account) }}" class="sg-auth-secondary-action">
                @csrf
                <input type="hidden" name="form_context" value="{{ $context }}">
                <button class="sg-button sg-button-secondary sg-button-small" type="submit" data-submit-button>Отправить код повторно</button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.telegram.accounts.send-code', $account) }}" class="sg-auth-step {{ $isConnected ? '' : 'sg-auth-step-active' }}">
                @csrf
                <input type="hidden" name="form_context" value="{{ $context }}">
                <div>
                    <span class="sg-auth-step-number">1</span>
                    <h4>{{ $isConnected ? 'Переподключить по номеру' : 'Запросить код' }}</h4>
                    <p>Telegram отправит код на номер, указанный в настройках аккаунта.</p>
                </div>
                <button class="sg-button {{ $isConnected ? 'sg-button-secondary' : 'sg-button-primary' }}" type="submit" data-submit-button>Отправить код</button>
            </form>
        @endif
    @else
        @if ($awaitingPassword)
            <form method="POST" action="{{ route('admin.telegram.accounts.password', $account) }}" class="sg-auth-step sg-auth-step-active">
                @csrf
                <input type="hidden" name="form_context" value="{{ $context }}">
                <div>
                    <span class="sg-auth-step-number">2</span>
                    <h4>Пароль 2FA</h4>
                    <p>QR-код подтверждён, но Telegram требует облачный пароль.</p>
                </div>
                <label class="sg-field">
                    <span>Пароль 2FA</span>
                    <input type="password" name="password" required autocomplete="current-password" autofocus>
                    @if (old('form_context') === $context) @error('password')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
                </label>
                <button class="sg-button sg-button-primary" type="submit" data-submit-button>Подтвердить пароль</button>
            </form>
        @elseif ($qrActive)
            <div class="sg-qr-panel sg-auth-step sg-auth-step-active">
                <div>
                    <span class="sg-auth-step-number">1</span>
                    <h4>Отсканируйте QR-код</h4>
                    <p>Telegram → Настройки → Устройства → Подключить устройство.</p>
                    <form method="POST" action="{{ route('admin.telegram.accounts.qr.wait', $account) }}">
                        @csrf
                        <input type="hidden" name="form_context" value="{{ $context }}">
                        <button class="sg-button sg-button-primary" type="submit" data-submit-button>Проверить подключение</button>
                    </form>
                </div>
                <div class="sg-qr-code-wrap">
                    <div class="sg-qr-code" data-qr-url="{{ $qrUrl }}"></div>
                    <small>Действует до {{ $account->auth_expires_at->timezone('Europe/Kyiv')->format('H:i:s') }}</small>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('admin.telegram.accounts.qr.start', $account) }}" class="sg-auth-step {{ $isConnected ? '' : 'sg-auth-step-active' }}">
                @csrf
                <input type="hidden" name="form_context" value="{{ $context }}">
                <div>
                    <span class="sg-auth-step-number">1</span>
                    <h4>{{ $isConnected ? 'Переподключить по QR-коду' : 'Создать QR-код' }}</h4>
                    <p>После создания QR-кода форма останется открытой для подтверждения.</p>
                </div>
                <button class="sg-button {{ $isConnected ? 'sg-button-secondary' : 'sg-button-primary' }}" type="submit" data-submit-button>Создать QR-код</button>
            </form>
        @endif
    @endif
</section>
