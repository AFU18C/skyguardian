@php
    $siteSettings ??= app(\App\Services\SiteContentService::class)->settings();
    $faviconUrl = !empty($siteSettings['favicon_path'])
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($siteSettings['favicon_path'])
        : null;
@endphp

<x-layouts.guest
    :title="'Двухфакторная проверка — '.($siteSettings['site_name'] ?? 'SkyGuardian')"
    :language="$siteSettings['language'] ?? 'ru'"
    :favicon="$faviconUrl"
    :theme="$siteSettings['theme'] ?? 'classic'"
>
    <main class="sg-login-page">
        <section class="sg-login-visual">
            <div class="sg-login-visual-content">
                <div class="sg-public-emblem">2FA</div>
                <p class="sg-eyebrow">Защищённый вход</p>
                <h1>SkyGuardian</h1>
                <p>Введите одноразовый код из приложения-аутентификатора или резервный код.</p>
            </div>
        </section>
        <section class="sg-login-form-wrap">
            <form class="sg-login-form" method="POST" action="{{ route('admin.mfa.challenge.store') }}">
                @csrf
                <div class="sg-form-heading"><p class="sg-eyebrow">Второй фактор</p><h2>Подтверждение входа</h2></div>
                <label class="sg-field">
                    <span>Код <b>*</b></span>
                    <input name="code" autocomplete="one-time-code" autocapitalize="characters" maxlength="32" required autofocus>
                    @error('code')<small class="sg-field-error">{{ $message }}</small>@enderror
                </label>
                <button class="sg-button sg-button-primary sg-button-block" type="submit" data-submit-button><span>Продолжить</span></button>
                <a class="sg-back-link" href="{{ route('admin.login') }}">← Вернуться ко входу</a>
            </form>
        </section>
    </main>
</x-layouts.guest>
