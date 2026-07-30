@php
    $publishedCount = $pages->getCollection()->where('status', \App\Models\SitePage::STATUS_PUBLISHED)->count();
    $draftCount = $pages->getCollection()->where('status', \App\Models\SitePage::STATUS_DRAFT)->count();
    $systemCount = $pages->getCollection()->where('is_system', true)->count() + 1;
@endphp

<x-layouts.admin title="Настройки сайта">
    <x-slot:actions>
        <a class="sg-button sg-button-primary" href="{{ route('admin.site-settings.pages.create') }}">+ Создать страницу</a>
    </x-slot:actions>

    <section class="site-settings-summary">
        <div class="site-settings-stat"><span>Всего страниц</span><strong>{{ $pages->total() + 1 }}</strong></div>
        <div class="site-settings-stat"><span>Опубликовано</span><strong>{{ $publishedCount }}</strong></div>
        <div class="site-settings-stat"><span>Черновики</span><strong>{{ $draftCount }}</strong></div>
        <div class="site-settings-stat"><span>Системные</span><strong>{{ $systemCount }}</strong></div>
    </section>

    @if($errors->any())
        <div class="sg-inline-error">
            <strong>Изменения не сохранены.</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="site-settings-panels">
        <details class="site-settings-panel" open>
            <summary>
                <span class="site-settings-panel-title">
                    <strong>Страницы сайта</strong>
                    <small>Создание, публикация, копирование и редактирование публичных страниц.</small>
                </span>
            </summary>
            <div class="site-settings-panel-body">
                <div class="site-pages-toolbar">
                    <div>
                        <p class="sg-eyebrow">Публичный контент</p>
                        <h2>Все страницы</h2>
                    </div>
                    <a class="sg-button sg-button-primary" href="{{ route('admin.site-settings.pages.create') }}">Создать страницу</a>
                </div>

                <div class="site-pages-grid">
                    <article class="site-page-row">
                        <div class="site-page-row-main">
                            <strong>Авторизация <span class="site-system-badge">Системная</span></strong>
                            <small>{{ url('/admin/login') }}</small>
                        </div>
                        <div class="site-page-row-meta">
                            <span class="site-status-badge is-published">Активна</span>
                            <small>Защищённая страница</small>
                        </div>
                        <div class="site-page-row-meta is-updated">
                            <strong>Не удаляется</strong>
                            <small>Логика входа защищена</small>
                        </div>
                        <div class="site-page-row-actions">
                            <a class="sg-button sg-button-small sg-button-secondary" href="{{ route('admin.site-settings.login.edit') }}">Редактировать</a>
                            <a class="sg-button sg-button-small sg-button-secondary" href="{{ route('admin.site-settings.login.preview') }}" target="_blank" rel="noopener">Предпросмотр</a>
                        </div>
                    </article>

                    @forelse($pages as $page)
                        <article class="site-page-row">
                            <div class="site-page-row-main">
                                <strong>
                                    {{ $page->title }}
                                    @if($page->is_system)<span class="site-system-badge">Системная</span>@endif
                                </strong>
                                <small>{{ $page->system_key === 'home' ? url('/') : url('/'.$page->slug) }}</small>
                            </div>
                            <div class="site-page-row-meta">
                                <span class="site-status-badge is-{{ $page->status }}">{{ $page->displayStatus() }}</span>
                                <small>{{ $page->show_in_menu ? 'Показывается в меню' : 'Не в меню' }}</small>
                            </div>
                            <div class="site-page-row-meta is-updated">
                                <strong>{{ $page->updated_at->timezone($settings['timezone'] ?? 'Europe/Kyiv')->format('d.m.Y H:i') }}</strong>
                                <small>Последнее изменение</small>
                            </div>
                            <div class="site-page-row-actions">
                                <a class="sg-button sg-button-small sg-button-secondary" href="{{ route('admin.site-settings.pages.edit', $page) }}">Редактировать</a>
                                <a class="sg-button sg-button-small sg-button-secondary" href="{{ route('admin.site-settings.pages.preview', $page) }}" target="_blank" rel="noopener">Открыть</a>
                                <form method="POST" action="{{ route('admin.site-settings.pages.duplicate', $page) }}">
                                    @csrf
                                    <button class="sg-button sg-button-small sg-button-secondary" type="submit">Копировать</button>
                                </form>
                                @unless($page->is_system)
                                    <form method="POST" action="{{ route('admin.site-settings.pages.destroy', $page) }}" data-confirm="Удалить страницу без возможности восстановления?">
                                        @csrf @method('DELETE')
                                        <button class="sg-button sg-button-small sg-button-danger" type="submit">Удалить</button>
                                    </form>
                                @endunless
                            </div>
                        </article>
                    @empty
                        <section class="sg-empty-state sg-empty-state-compact">
                            <h2>Публичные страницы ещё не созданы</h2>
                            <a class="sg-button sg-button-primary" href="{{ route('admin.site-settings.pages.create') }}">Создать первую страницу</a>
                        </section>
                    @endforelse
                </div>

                <div class="sg-pagination">{{ $pages->links() }}</div>
            </div>
        </details>

        <details class="site-settings-panel">
            <summary>
                <span class="site-settings-panel-title">
                    <strong>Главное меню</strong>
                    <small>Порядок страниц, внешние ссылки и вложенные пункты.</small>
                </span>
            </summary>
            <div class="site-settings-panel-body">
                <div class="site-menu-list">
                    @forelse($menuItems as $item)
                        <div class="site-menu-item-form {{ $item->parent_id ? 'is-child' : '' }}">
                            <form id="site-menu-update-{{ $item->id }}" method="POST" action="{{ route('admin.site-settings.menu.update', $item) }}"></form>
                            @csrf
                            <div class="sg-field">
                                <label for="menu-label-{{ $item->id }}">Название</label>
                                <input id="menu-label-{{ $item->id }}" name="label" value="{{ $item->label }}" maxlength="120" required form="site-menu-update-{{ $item->id }}">
                            </div>
                            <div class="sg-field">
                                <label for="menu-url-{{ $item->id }}">Ссылка</label>
                                <input id="menu-url-{{ $item->id }}" name="url" value="{{ $item->site_page_id ? $item->page?->publicUrl() : $item->url }}" @readonly($item->site_page_id) form="site-menu-update-{{ $item->id }}">
                            </div>
                            <div class="sg-field">
                                <label for="menu-parent-{{ $item->id }}">Родительский пункт</label>
                                <select id="menu-parent-{{ $item->id }}" name="parent_id" form="site-menu-update-{{ $item->id }}">
                                    <option value="">Нет</option>
                                    @foreach($parentMenuItems->where('id', '!=', $item->id) as $parent)
                                        <option value="{{ $parent->id }}" @selected($item->parent_id === $parent->id)>{{ $parent->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sg-field">
                                <label for="menu-order-{{ $item->id }}">Порядок</label>
                                <input id="menu-order-{{ $item->id }}" name="sort_order" type="number" min="0" max="10000" value="{{ $item->sort_order }}" required form="site-menu-update-{{ $item->id }}">
                            </div>
                            <div>
                                <div class="site-menu-flags">
                                    <label><input type="checkbox" name="is_active" value="1" @checked($item->is_active) form="site-menu-update-{{ $item->id }}"> Активен</label>
                                    <label><input type="checkbox" name="open_in_new_tab" value="1" @checked($item->open_in_new_tab) form="site-menu-update-{{ $item->id }}"> Новая вкладка</label>
                                </div>
                                <div class="site-menu-actions">
                                    <button class="sg-button sg-button-small sg-button-primary" type="submit" form="site-menu-update-{{ $item->id }}">Сохранить</button>
                                    <form method="POST" action="{{ route('admin.site-settings.menu.destroy', $item) }}" data-confirm="Удалить пункт меню? Страница останется.">
                                        @csrf @method('DELETE')
                                        <button class="sg-button sg-button-small sg-button-danger" type="submit">Удалить</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p>Пункты меню ещё не добавлены.</p>
                    @endforelse
                </div>

                <section class="site-editor-card">
                    <header>
                        <h3>Добавить пункт меню</h3>
                        <p>Можно добавить опубликованную страницу или внешнюю ссылку.</p>
                    </header>
                    <div class="site-editor-card-body">
                        <form method="POST" action="{{ route('admin.site-settings.menu.store') }}">
                            @csrf
                            <div class="sg-form-grid">
                                <div class="sg-field">
                                    <label for="new-menu-type">Тип</label>
                                    <select id="new-menu-type" name="type" required>
                                        <option value="page">Страница сайта</option>
                                        <option value="external">Внешняя ссылка</option>
                                    </select>
                                </div>
                                <div class="sg-field">
                                    <label for="new-menu-page">Страница</label>
                                    <select id="new-menu-page" name="site_page_id">
                                        <option value="">Выберите страницу</option>
                                        @foreach($publishedPages as $publishedPage)
                                            <option value="{{ $publishedPage->id }}">{{ $publishedPage->title }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sg-field">
                                    <label for="new-menu-label">Название пункта</label>
                                    <input id="new-menu-label" name="label" maxlength="120" required>
                                </div>
                                <div class="sg-field">
                                    <label for="new-menu-url">Внешняя ссылка</label>
                                    <input id="new-menu-url" name="url" placeholder="https://example.com или /page">
                                </div>
                                <div class="sg-field">
                                    <label for="new-menu-parent">Родительский пункт</label>
                                    <select id="new-menu-parent" name="parent_id">
                                        <option value="">Нет</option>
                                        @foreach($parentMenuItems as $parent)<option value="{{ $parent->id }}">{{ $parent->label }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="sg-field">
                                    <label for="new-menu-order">Порядок</label>
                                    <input id="new-menu-order" name="sort_order" type="number" min="0" max="10000" value="100">
                                </div>
                            </div>
                            <label class="sg-switch-row">
                                <strong>Открывать в новой вкладке</strong>
                                <input name="open_in_new_tab" type="checkbox" value="1">
                            </label>
                            <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Добавить пункт</button></div>
                        </form>
                    </div>
                </section>
            </div>
        </details>

        <details class="site-settings-panel">
            <summary>
                <span class="site-settings-panel-title">
                    <strong>Оформление и общие параметры</strong>
                    <small>Название сайта, логотип, favicon, язык, часовой пояс и тема.</small>
                </span>
            </summary>
            <div class="site-settings-panel-body">
                <form method="POST" action="{{ route('admin.site-settings.general.update') }}" enctype="multipart/form-data">
                    @csrf @method('PUT')
                    <div class="sg-form-grid">
                        <div class="sg-field">
                            <label for="site-name">Название сайта</label>
                            <input id="site-name" name="site_name" value="{{ old('site_name', $settings['site_name'] ?? 'SkyGuardian') }}" maxlength="120" required>
                        </div>
                        <div class="sg-field">
                            <label for="site-tagline">Подзаголовок</label>
                            <input id="site-tagline" name="site_tagline" value="{{ old('site_tagline', $settings['site_tagline'] ?? '') }}" maxlength="255">
                        </div>
                        <div class="sg-field">
                            <label for="site-language">Язык публичного сайта</label>
                            <select id="site-language" name="language" required>
                                <option value="ru" @selected(($settings['language'] ?? 'ru') === 'ru')>Русский</option>
                                <option value="uk" @selected(($settings['language'] ?? 'ru') === 'uk')>Українська</option>
                            </select>
                        </div>
                        <div class="sg-field">
                            <label for="site-timezone">Часовой пояс</label>
                            <select id="site-timezone" name="timezone" required>
                                <option value="Europe/Kyiv" @selected(($settings['timezone'] ?? 'Europe/Kyiv') === 'Europe/Kyiv')>Europe/Kyiv</option>
                                <option value="UTC" @selected(($settings['timezone'] ?? 'Europe/Kyiv') === 'UTC')>UTC</option>
                            </select>
                        </div>
                        <div class="sg-field">
                            <label for="site-theme">Тема публичного сайта</label>
                            <select id="site-theme" name="theme" required>
                                <option value="classic" @selected(($settings['theme'] ?? 'classic') === 'classic')>Фирменная</option>
                                <option value="light" @selected(($settings['theme'] ?? 'classic') === 'light')>Светлая</option>
                                <option value="dark" @selected(($settings['theme'] ?? 'classic') === 'dark')>Тёмная</option>
                            </select>
                        </div>
                    </div>

                    <div class="sg-form-grid">
                        <div class="sg-field">
                            <label for="site-logo">Логотип</label>
                            <input id="site-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                            @if(!empty($settings['logo_path']))
                                <div class="site-brand-preview">
                                    <img src="{{ Storage::disk('public')->url($settings['logo_path']) }}" alt="Логотип">
                                    <label><input name="remove_logo" type="checkbox" value="1"> Удалить логотип</label>
                                </div>
                            @endif
                        </div>
                        <div class="sg-field">
                            <label for="site-favicon">Favicon</label>
                            <input id="site-favicon" name="favicon" type="file" accept="image/png,image/x-icon,image/svg+xml">
                            @if(!empty($settings['favicon_path']))
                                <div class="site-brand-preview">
                                    <img src="{{ Storage::disk('public')->url($settings['favicon_path']) }}" alt="Favicon">
                                    <label><input name="remove_favicon" type="checkbox" value="1"> Удалить favicon</label>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="sg-record-actions"><button class="sg-button sg-button-primary" type="submit">Сохранить общие настройки</button></div>
                </form>
            </div>
        </details>
    </div>
</x-layouts.admin>
