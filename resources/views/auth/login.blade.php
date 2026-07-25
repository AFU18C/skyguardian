<x-layouts.guest title="Вход — SkyGuardian">
    <main class="sg-login-page">
        <section class="sg-login-visual">
            <div class="sg-login-visual-content">
                <div class="sg-public-emblem">SG</div>
                <p class="sg-eyebrow">Защищённый доступ</p>
                <h1>SkyGuardian</h1>
                <p>Панель управления источниками, техническими аккаунтами и Telegram-интеграцией.</p>
            </div>
        </section>

        <section class="sg-login-form-wrap">
            <form class="sg-login-form" method="POST" action="{{ route('admin.login.store') }}">
                @csrf
                <div class="sg-form-heading">
                    <p class="sg-eyebrow">Административная панель</p>
                    <h2>Вход в систему</h2>
                    <p>Введите Email и пароль администратора.</p>
                </div>

                <label class="sg-field">
                    <span>Email <b>*</b></span>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                    @error('email')<small class="sg-field-error">{{ $message }}</small>@enderror
                </label>

                <label class="sg-field">
                    <span>Пароль <b>*</b></span>
                    <input type="password" name="password" required autocomplete="current-password">
                    @error('password')<small class="sg-field-error">{{ $message }}</small>@enderror
                </label>

                <label class="sg-check">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    <span>Запомнить меня</span>
                </label>

                <button class="sg-button sg-button-primary sg-button-block" type="submit" data-submit-button>
                    <span>Войти</span>
                </button>

                <a class="sg-back-link" href="{{ route('home') }}">← Вернуться на сайт</a>
            </form>
        </section>
    </main>
</x-layouts.guest>
