<x-layouts.admin title="Страница авторизации">
    <x-slot:actions>
        <a class="sg-button sg-button-secondary" href="{{ route('admin.site-settings') }}">← Настройки сайта</a>
        <a class="sg-button sg-button-secondary" href="{{ route('admin.site-settings.login.preview') }}" target="_blank" rel="noopener">Предпросмотр</a>
    </x-slot:actions>

    <section class="site-editor-header">
        <div>
            <p class="sg-eyebrow">Защищённая системная страница</p>
            <h2>Авторизация администратора</h2>
            <p>Здесь меняются только тексты и оформление. Адрес <strong>/admin/login</strong>, проверка пароля, ограничение попыток и логика входа остаются защищёнными.</p>
        </div>
        <span class="site-status-badge is-published">Активна</span>
    </section>

    @if($errors->any())
        <div class="sg-inline-error">
            <strong>Настройки не сохранены.</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.site-settings.login.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="site-editor-layout">
            <div class="site-editor-main">
                <section class="site-editor-card">
                    <header>
                        <h3>Левая часть страницы</h3>
                        <p>Название проекта, пояснение и визуальное оформление.</p>
                    </header>
                    <div class="site-editor-card-body">
                        <div class="sg-form-grid">
                            <div class="sg-field">
                                <label for="login-visual-eyebrow">Метка над заголовком</label>
                                <input id="login-visual-eyebrow" name="login_visual_eyebrow" value="{{ old('login_visual_eyebrow', $settings['login_visual_eyebrow']) }}" maxlength="120" required>
                            </div>
                            <div class="sg-field">
                                <label for="login-visual-title">Большой заголовок</label>
                                <input id="login-visual-title" name="login_visual_title" value="{{ old('login_visual_title', $settings['login_visual_title']) }}" maxlength="160" required>
                            </div>
                        </div>
                        <div class="sg-field">
                            <label for="login-visual-description">Описание</label>
                            <textarea id="login-visual-description" name="login_visual_description" rows="5" maxlength="1000">{{ old('login_visual_description', $settings['login_visual_description']) }}</textarea>
                        </div>
                        <div class="sg-form-grid">
                            <div class="sg-field">
                                <label for="login-logo">Отдельный логотип авторизации</label>
                                <input id="login-logo" name="login_logo" type="file" accept="image/png,image/jpeg,image/webp">
                                <small>Если не загружен, используется общий логотип сайта или знак SG.</small>
                                @if(!empty($settings['login_logo_path']))
                                    <div class="site-brand-preview">
                                        <img src="{{ Storage::disk('public')->url($settings['login_logo_path']) }}" alt="Логотип авторизации">
                                        <label><input name="remove_login_logo" type="checkbox" value="1"> Удалить отдельный логотип</label>
                                    </div>
                                @endif
                            </div>
                            <div class="sg-field">
                                <label for="login-background">Фоновое изображение</label>
                                <input id="login-background" name="login_background" type="file" accept="image/png,image/jpeg,image/webp">
                                <small>Рекомендуемый размер: 1920×1080, до 8 МБ.</small>
                                @if(!empty($settings['login_background_path']))
                                    <div class="site-brand-preview is-wide">
                                        <img src="{{ Storage::disk('public')->url($settings['login_background_path']) }}" alt="Фон авторизации">
                                        <label><input name="remove_login_background" type="checkbox" value="1"> Удалить фон</label>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="site-editor-card">
                    <header>
                        <h3>Форма входа</h3>
                        <p>Подписи и тексты, которые видит администратор.</p>
                    </header>
                    <div class="site-editor-card-body">
                        <div class="sg-form-grid">
                            <div class="sg-field">
                                <label for="login-form-eyebrow">Метка формы</label>
                                <input id="login-form-eyebrow" name="login_form_eyebrow" value="{{ old('login_form_eyebrow', $settings['login_form_eyebrow']) }}" maxlength="120" required>
                            </div>
                            <div class="sg-field">
                                <label for="login-form-title">Заголовок формы</label>
                                <input id="login-form-title" name="login_form_title" value="{{ old('login_form_title', $settings['login_form_title']) }}" maxlength="160" required>
                            </div>
                        </div>
                        <div class="sg-field">
                            <label for="login-form-description">Описание формы</label>
                            <textarea id="login-form-description" name="login_form_description" rows="3" maxlength="500">{{ old('login_form_description', $settings['login_form_description']) }}</textarea>
                        </div>
                        <div class="sg-form-grid">
                            <div class="sg-field">
                                <label for="login-email-label">Подпись Email</label>
                                <input id="login-email-label" name="login_email_label" value="{{ old('login_email_label', $settings['login_email_label']) }}" maxlength="80" required>
                            </div>
                            <div class="sg-field">
                                <label for="login-password-label">Подпись пароля</label>
                                <input id="login-password-label" name="login_password_label" value="{{ old('login_password_label', $settings['login_password_label']) }}" maxlength="80" required>
                            </div>
                            <div class="sg-field">
                                <label for="login-remember-label">Текст «Запомнить меня»</label>
                                <input id="login-remember-label" name="login_remember_label" value="{{ old('login_remember_label', $settings['login_remember_label']) }}" maxlength="120" required>
                            </div>
                            <div class="sg-field">
                                <label for="login-button-label">Текст кнопки</label>
                                <input id="login-button-label" name="login_button_label" value="{{ old('login_button_label', $settings['login_button_label']) }}" maxlength="80" required>
                            </div>
                        </div>
                        <div class="sg-field">
                            <label for="login-back-label">Текст ссылки возврата</label>
                            <input id="login-back-label" name="login_back_label" value="{{ old('login_back_label', $settings['login_back_label']) }}" maxlength="120" required>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="site-editor-sidebar">
                <section class="site-editor-card">
                    <header>
                        <h3>Цвета страницы</h3>
                        <p>Без изменения общей темы публичного сайта.</p>
                    </header>
                    <div class="site-editor-card-body">
                        <div class="sg-field site-color-field">
                            <label for="login-accent-color">Основной цвет</label>
                            <div><input id="login-accent-color" name="login_accent_color" type="color" value="{{ old('login_accent_color', $settings['login_accent_color']) }}"><code>{{ old('login_accent_color', $settings['login_accent_color']) }}</code></div>
                        </div>
                        <div class="sg-field site-color-field">
                            <label for="login-background-color">Цвет левой части</label>
                            <div><input id="login-background-color" name="login_background_color" type="color" value="{{ old('login_background_color', $settings['login_background_color']) }}"><code>{{ old('login_background_color', $settings['login_background_color']) }}</code></div>
                        </div>
                        <div class="sg-field site-color-field">
                            <label for="login-panel-color">Цвет панели формы</label>
                            <div><input id="login-panel-color" name="login_panel_color" type="color" value="{{ old('login_panel_color', $settings['login_panel_color']) }}"><code>{{ old('login_panel_color', $settings['login_panel_color']) }}</code></div>
                        </div>
                    </div>
                </section>

                <section class="site-editor-card">
                    <header><h3>Безопасность</h3></header>
                    <div class="site-editor-card-body">
                        <div class="sg-notice sg-notice-warning">
                            Через этот редактор нельзя изменить URL входа, Email администратора, пароль, лимит попыток или отключить авторизацию.
                        </div>
                    </div>
                </section>

                <div class="site-editor-actions">
                    <button class="sg-button sg-button-primary" type="submit">Сохранить страницу авторизации</button>
                    <a class="sg-button sg-button-secondary" href="{{ route('admin.site-settings.login.preview') }}" target="_blank" rel="noopener">Открыть предпросмотр</a>
                </div>
            </aside>
        </div>
    </form>
</x-layouts.admin>
