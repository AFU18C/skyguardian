@php
    $isEdit = isset($account) && $account;
    $context = $isEdit ? 'account-'.$account->id : 'account-create';
    $useOld = old('form_context') === $context;
    $selectedApi = $useOld ? old('telegram_api_id') : ($account->telegram_api_id ?? ($apis->first()?->id));
    $authMethod = $useOld ? old('auth_method', 'phone') : ($account->auth_method ?? 'phone');
@endphp

<form method="POST" action="{{ $action }}" class="sg-form" data-dirty-form data-account-form>
    @csrf
    @if ($isEdit) @method('PUT') @endif
    <input type="hidden" name="form_context" value="{{ $context }}">

    <div class="sg-form-grid">
        <label class="sg-field sg-field-wide">
            <span>Внутреннее название <b>*</b></span>
            <input type="text" name="name" maxlength="255" required
                   value="{{ $useOld ? old('name') : ($account->name ?? '') }}"
                   placeholder="Например: Основной аккаунт">
            @if ($useOld) @error('name')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>

        <label class="sg-field sg-field-wide">
            <span>Telegram API <b>*</b></span>
            <select name="telegram_api_id" data-api-select @required($isEdit || $apis->isNotEmpty())>
                @if (! $isEdit)
                    <option value="">Создать новую конфигурацию</option>
                @endif
                @foreach ($apis as $api)
                    <option value="{{ $api->id }}" @selected((string) $selectedApi === (string) $api->id)>
                        {{ $api->name }} — API ID {{ mb_substr((string) $api->api_id, 0, 4) }}••••
                    </option>
                @endforeach
            </select>
            @if ($useOld) @error('telegram_api_id')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>
    </div>

    @unless ($isEdit)
        <section class="sg-subpanel" data-new-api-fields @if($selectedApi) hidden @endif>
            <div class="sg-section-heading"><div><h3>Новая конфигурация Telegram API</h3><p>Эти данные будут сохранены зашифрованно.</p></div></div>
            <div class="sg-form-grid">
                <label class="sg-field sg-field-wide">
                    <span>Название API <b>*</b></span>
                    <input type="text" name="new_api_name" maxlength="255" value="{{ $useOld ? old('new_api_name') : '' }}" placeholder="Основной Telegram API">
                    @if ($useOld) @error('new_api_name')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
                </label>
                <label class="sg-field">
                    <span>API ID <b>*</b></span>
                    <input type="number" name="new_api_id" min="1" value="{{ $useOld ? old('new_api_id') : '' }}">
                    @if ($useOld) @error('new_api_id')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
                </label>
                <label class="sg-field">
                    <span>API Hash <b>*</b></span>
                    <input type="password" name="new_api_hash" minlength="16" maxlength="255" autocomplete="new-password">
                    @if ($useOld) @error('new_api_hash')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
                </label>
            </div>
        </section>
    @endunless

    <section class="sg-subpanel">
        <div class="sg-section-heading"><div><h3>Способ подключения</h3><p>Выберите рабочий способ авторизации технического аккаунта.</p></div></div>
        <div class="sg-choice-grid">
            <label class="sg-choice-card">
                <input type="radio" name="auth_method" value="phone" @checked($authMethod === 'phone')>
                <span class="sg-choice-icon">☎</span>
                <span><strong>Номер телефона</strong><small>Код Telegram и пароль 2FA при необходимости.</small></span>
            </label>
            <label class="sg-choice-card">
                <input type="radio" name="auth_method" value="qr" @checked($authMethod === 'qr')>
                <span class="sg-choice-icon">▦</span>
                <span><strong>QR-код</strong><small>Подключение через раздел «Устройства» в Telegram.</small></span>
            </label>
        </div>

        <label class="sg-field" data-phone-field>
            <span>Номер телефона {{ $isEdit ? '' : '*' }}</span>
            <input type="tel" name="phone" maxlength="32"
                   value="{{ $useOld ? old('phone') : '' }}"
                   placeholder="{{ $isEdit ? 'Оставьте пустым, чтобы не изменять' : '+380...' }}" autocomplete="tel">
            @if ($isEdit)<small>Текущий номер скрыт частично и не передаётся в HTML.</small>@endif
            @if ($useOld) @error('phone')<small class="sg-field-error">{{ $message }}</small>@enderror @endif
        </label>
    </section>

    <label class="sg-switch-row">
        <span><strong>Аккаунт активен</strong><small>Отключённый аккаунт не используется автоматической обработкой.</small></span>
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked((bool) ($useOld ? old('is_active') : ($account->is_active ?? true)))>
    </label>

    <div class="sg-form-actions">
        <button type="button" class="sg-button sg-button-secondary" data-modal-close>Отмена</button>
        <button type="submit" class="sg-button sg-button-primary" data-submit-button>Сохранить</button>
    </div>
</form>
