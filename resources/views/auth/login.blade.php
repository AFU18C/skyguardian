@php
    $siteSettings ??= app(\App\Services\SiteContentService::class)->settings();
    $isPreview = (bool) ($isPreview ?? false);
    $logoPath = $siteSettings['login_logo_path'] ?: ($siteSettings['logo_path'] ?? null);
    $logoUrl = $logoPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) : null;
    $backgroundUrl = !empty($siteSettings['login_background_path'])
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($siteSettings['login_background_path'])
        : null;
    $faviconUrl = !empty($siteSettings['favicon_path'])
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($siteSettings['favicon_path'])
        : null;
    $loginStyle = sprintf(
        '--sg-login-accent:%s;--sg-login-background:%s;--sg-login-panel:%s;',
        $siteSettings['login_accent_color'] ?? '#687052',
        $siteSettings['login_background_color'] ?? '#20231d',
        $siteSettings['login_panel_color'] ?? '#f8f5e9',
    );
    if ($backgroundUrl) {
        $loginStyle .= '--sg-login-image:url("'.e($backgroundUrl).'");';
    }
@endphp

<x-layouts.guest
    :title="($siteSettings['login_form_title'] ?? 'Вход в систему').' — '.($siteSettings['site_name'] ?? 'SkyGuardian')"
    :language="$siteSettings['language'] ?? 'ru'"
    :favicon="$faviconUrl"
    :theme="$siteSettings['theme'] ?? 'classic'"
>
    @if($isPreview)
        <div class="sg-login-preview-bar">
            <strong>Предпросмотр авторизации</strong>
            <span>Форма отключена и доступна только администратору.</span>
            <a href="{{ route('admin.site-settings.login.edit') }}">Вернуться к настройкам</a>
        </div>
    @endif

    <main class="sg-login-page {{ $isPreview ? 'is-preview' : '' }}" style="{{ $loginStyle }}">
        <section class="sg-login-visual">
            <div class="sg-login-visual-content">
                @if($logoUrl)
                    <img class="sg-login-logo" src="{{ $logoUrl }}" alt="{{ $siteSettings['site_name'] ?? 'SkyGuardian' }}">
                @else
                    <div class="sg-public-emblem">SG</div>
                @endif
                <p class="sg-eyebrow">{{ $siteSettings['login_visual_eyebrow'] ?? 'Защищённый доступ' }}</p>
                <h1>{{ $siteSettings['login_visual_title'] ?? 'SkyGuardian' }}</h1>
                @if(!empty($siteSettings['login_visual_description']))
                    <p>{{ $siteSettings['login_visual_description'] }}</p>
                @endif
            </div>
        </section>

        <section class="sg-login-form-wrap">
            <form class="sg-login-form" method="POST" action="{{ route('admin.login.store') }}" @if($isPreview) onsubmit="return false" @endif>
                @csrf
                <div class="sg-form-heading">
                    <p class="sg-eyebrow">{{ $siteSettings['login_form_eyebrow'] ?? 'Административная панель' }}</p>
                    <h2>{{ $siteSettings['login_form_title'] ?? 'Вход в систему' }}</h2>
                    @if(!empty($siteSettings['login_form_description']))
                        <p>{{ $siteSettings['login_form_description'] }}</p>
                    @endif
                </div>

                <label class="sg-field">
                    <span>{{ $siteSettings['login_email_label'] ?? 'Email' }} <b>*</b></span>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" @disabled($isPreview)>
                    @error('email')<small class="sg-field-error">{{ $message }}</small>@enderror
                </label>

                <label class="sg-field">
                    <span>{{ $siteSettings['login_password_label'] ?? 'Пароль' }} <b>*</b></span>
                    <input type="password" name="password" required autocomplete="current-password" @disabled($isPreview)>
                    @error('password')<small class="sg-field-error">{{ $message }}</small>@enderror
                </label>

                <label class="sg-check">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember')) @disabled($isPreview)>
                    <span>{{ $siteSettings['login_remember_label'] ?? 'Запомнить меня' }}</span>
                </label>

                <button class="sg-button sg-button-primary sg-button-block" type="submit" data-submit-button @disabled($isPreview)>
                    <span>{{ $siteSettings['login_button_label'] ?? 'Войти' }}</span>
                </button>

                <a class="sg-back-link" href="{{ route('home') }}">← {{ $siteSettings['login_back_label'] ?? 'Вернуться на сайт' }}</a>
            </form>
        </section>
    </main>
</x-layouts.guest>
