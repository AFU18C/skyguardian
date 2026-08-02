@php
    $editing = $page->exists;
    $siteSettings = app(\App\Services\SiteContentService::class)->settings();
    $pageBlocks = old('blocks_json') ? json_decode(old('blocks_json'), true) : ($page->blocks ?? []);
    $publishedAt = old('published_at', $page->published_at?->timezone($siteSettings['timezone'] ?? 'Europe/Kyiv')->format('Y-m-d\TH:i'));
@endphp

<x-layouts.admin title="{{ $editing ? 'Редактирование страницы' : 'Новая страница' }}">
    <x-slot:actions>
        <a class="sg-button sg-button-secondary" href="{{ route('admin.site-settings') }}">← К списку страниц</a>
        @if($editing)
            <a class="sg-button sg-button-secondary" href="{{ route('admin.site-settings.pages.preview', $page) }}" target="_blank" rel="noopener">Предпросмотр</a>
        @endif
    </x-slot:actions>

    <div
        class="site-page-editor"
        data-site-page-editor
        data-upload-url="{{ route('admin.site-settings.media.store') }}"
    >
        <div class="site-editor-header">
            <div>
                <p class="sg-eyebrow">Публичный сайт</p>
                <h2>{{ $editing ? $page->title : 'Создание новой страницы' }}</h2>
                <p>Страница редактируется независимо от новостей, тревог, Telegram и групп-каналов.</p>
            </div>
            @if($editing)
                <span class="site-status-badge is-{{ $page->status }}">{{ $page->displayStatus() }}</span>
            @endif
        </div>

        @if($errors->any())
            <div class="sg-inline-error">
                <strong>Страница не сохранена.</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        @if($editing)
            <form id="site-page-duplicate-form" method="POST" action="{{ route('admin.site-settings.pages.duplicate', $page) }}">
                @csrf
            </form>
            @unless($page->is_system)
                <form id="site-page-delete-form" method="POST" action="{{ route('admin.site-settings.pages.destroy', $page) }}" data-confirm="Удалить страницу без возможности восстановления?">
                    @csrf @method('DELETE')
                </form>
            @endunless
        @endif

        <form
            id="site-page-main-form"
            method="POST"
            action="{{ $editing ? route('admin.site-settings.pages.update', $page) : route('admin.site-settings.pages.store') }}"
            enctype="multipart/form-data"
        >
            @csrf
            @if($editing) @method('PUT') @endif
            <input type="hidden" name="blocks_json" value="{{ old('blocks_json', json_encode($pageBlocks, JSON_UNESCAPED_UNICODE)) }}">
            <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" type="application/json" data-initial-blocks>@json($pageBlocks)</script>

            <div class="site-editor-layout">
                <div class="site-editor-main">
                    <section class="site-editor-card">
                        <header>
                            <h3>Основная информация</h3>
                            <p>Название, адрес, заголовок и краткое описание страницы.</p>
                        </header>
                        <div class="site-editor-card-body">
                            <div class="sg-form-grid">
                                <div class="sg-field">
                                    <label for="site-page-title">Название страницы</label>
                                    <input id="site-page-title" name="title" value="{{ old('title', $page->title) }}" maxlength="180" required>
                                </div>
                                <div class="sg-field">
                                    <label for="site-page-slug">URL страницы</label>
                                    <div class="site-slug-prefix">
                                        <span>/</span>
                                        <input id="site-page-slug" name="slug" value="{{ old('slug', $page->slug) }}" maxlength="180" required @readonly($page->is_system)>
                                    </div>
                                    @if($page->is_system)<small>URL системной страницы защищён.</small>@else<small>Только латинские буквы, цифры и дефисы.</small>@endif
                                </div>
                            </div>
                            <div class="sg-field">
                                <label for="site-page-heading">Заголовок на странице</label>
                                <input id="site-page-heading" name="heading" value="{{ old('heading', $page->heading) }}" maxlength="220" placeholder="Если пусто — используется название страницы">
                            </div>
                            <div class="sg-field">
                                <label for="site-page-excerpt">Краткое описание</label>
                                <textarea id="site-page-excerpt" name="excerpt" rows="4" maxlength="2000">{{ old('excerpt', $page->excerpt) }}</textarea>
                            </div>
                        </div>
                    </section>

                    <section class="site-editor-card">
                        <header>
                            <h3>Содержимое страницы</h3>
                            <p>Добавляйте блоки, меняйте порядок, скрывайте и дублируйте их.</p>
                        </header>
                        <div class="site-editor-card-body">
                            <div class="site-block-toolbar">
                                <div class="sg-field">
                                    <label for="site-add-block-type">Тип нового блока</label>
                                    <select id="site-add-block-type" data-add-block-type>
                                        <option value="heading">Заголовок</option>
                                        <option value="text">Текст</option>
                                        <option value="image">Изображение</option>
                                        <option value="gallery">Галерея</option>
                                        <option value="video">Видео</option>
                                        <option value="button">Кнопка</option>
                                        <option value="list">Список</option>
                                        <option value="card">Информационная карточка</option>
                                        <option value="divider">Разделитель</option>
                                        <option value="columns">Две колонки</option>
                                        <option value="contacts">Контакты</option>
                                        <option value="telegram">Telegram-ссылка</option>
                                        <option value="html">HTML-блок</option>
                                    </select>
                                </div>
                                <button class="sg-button sg-button-secondary" type="button" data-add-block>+ Добавить блок</button>
                            </div>
                            <div class="site-block-empty" data-blocks-empty>
                                Добавьте первый блок. Он появится здесь и будет сохранён вместе со страницей.
                            </div>
                            <div class="site-block-editor" data-blocks-root></div>
                        </div>
                    </section>

                    <section class="site-editor-card">
                        <header>
                            <h3>SEO и изображения</h3>
                            <p>Данные для поисковых систем, Telegram и социальных сетей.</p>
                        </header>
                        <div class="site-editor-card-body">
                            <div class="sg-field">
                                <label for="site-seo-title">SEO title</label>
                                <input id="site-seo-title" name="seo_title" value="{{ old('seo_title', $page->seo_title) }}" maxlength="180">
                            </div>
                            <div class="sg-field">
                                <label for="site-seo-description">SEO description</label>
                                <textarea id="site-seo-description" name="seo_description" rows="3" maxlength="500">{{ old('seo_description', $page->seo_description) }}</textarea>
                            </div>
                            <div class="sg-form-grid">
                                <div class="sg-field">
                                    <label for="site-featured-image">Основное изображение</label>
                                    <input id="site-featured-image" name="featured_image" type="file" accept="image/png,image/jpeg,image/webp">
                                    @if($page->featured_image_path)
                                        <div class="site-current-image">
                                            <img src="{{ Storage::disk('public')->url($page->featured_image_path) }}" alt="">
                                            <label><input type="checkbox" name="remove_featured_image" value="1"> Удалить изображение</label>
                                        </div>
                                    @endif
                                </div>
                                <div class="sg-field">
                                    <label for="site-social-image">Изображение для Telegram и соцсетей</label>
                                    <input id="site-social-image" name="social_image" type="file" accept="image/png,image/jpeg,image/webp">
                                    @if($page->social_image_path)
                                        <div class="site-current-image">
                                            <img src="{{ Storage::disk('public')->url($page->social_image_path) }}" alt="">
                                            <label><input type="checkbox" name="remove_social_image" value="1"> Удалить изображение</label>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="site-editor-sidebar">
                    <section class="site-editor-card">
                        <header>
                            <h3>Публикация</h3>
                            <p>Сохранение не закрывает редактор.</p>
                        </header>
                        <div class="site-editor-card-body">
                            <div class="sg-field">
                                <label for="site-published-at">Дата и время публикации</label>
                                <input id="site-published-at" name="published_at" type="datetime-local" value="{{ $publishedAt }}">
                                <small>Пустое поле — опубликовать сразу.</small>
                            </div>
                            <div class="site-editor-actions">
                                <button class="sg-button sg-button-secondary" type="submit" name="action" value="draft">Сохранить черновик</button>
                                <button class="sg-button sg-button-primary" type="submit" name="action" value="publish">Опубликовать</button>
                                <button class="sg-button sg-button-secondary" type="submit" name="action" value="hide">Сохранить и скрыть</button>
                            </div>
                        </div>
                    </section>

                    <section class="site-editor-card">
                        <header>
                            <h3>Пункт меню</h3>
                            <p>Добавление страницы в публичное меню сайта.</p>
                        </header>
                        <div class="site-editor-card-body">
                            <label class="sg-switch-row">
                                <strong>Показывать в меню</strong>
                                <input name="show_in_menu" type="checkbox" value="1" @checked(old('show_in_menu', $page->show_in_menu))>
                            </label>
                            <div class="sg-field">
                                <label for="site-menu-label">Название пункта</label>
                                <input id="site-menu-label" name="menu_label" value="{{ old('menu_label', $page->menu_label) }}" maxlength="120">
                            </div>
                            <div class="sg-field">
                                <label for="site-menu-order">Порядок</label>
                                <input id="site-menu-order" name="menu_order" type="number" min="0" max="10000" value="{{ old('menu_order', $page->menu_order ?? 100) }}">
                            </div>
                            <label class="sg-switch-row">
                                <strong>Открывать в новой вкладке</strong>
                                <input name="open_in_new_tab" type="checkbox" value="1" @checked(old('open_in_new_tab', $page->open_in_new_tab))>
                            </label>
                        </div>
                    </section>

                    @if($editing)
                        <section class="site-editor-card">
                            <header><h3>Действия</h3></header>
                            <div class="site-editor-card-body site-editor-actions">
                                <a class="sg-button sg-button-secondary" href="{{ route('admin.site-settings.pages.preview', $page) }}" target="_blank" rel="noopener">Открыть предпросмотр</a>
                                <button class="sg-button sg-button-secondary" type="submit" form="site-page-duplicate-form">Создать копию</button>
                            </div>
                        </section>

                        <section class="site-editor-danger">
                            @if($page->is_system)
                                <strong>Системная страница</strong>
                                <p>Её можно редактировать, скрывать и публиковать, но нельзя удалить.</p>
                            @else
                                <strong>Удаление страницы</strong>
                                <p>Страница и связанный пункт меню будут удалены.</p>
                                <button class="sg-button sg-button-danger" type="submit" form="site-page-delete-form">Удалить страницу</button>
                            @endif
                        </section>
                    @endif
                </aside>
            </div>
        </form>
    </div>
</x-layouts.admin>
