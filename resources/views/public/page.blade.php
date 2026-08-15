@php
    $pageTitle = $page->seo_title ?: $page->title;
    $description = $page->seo_description ?: $page->excerpt;
    $socialImage = $page->social_image_path ? url(\Illuminate\Support\Facades\Storage::disk('public')->url($page->social_image_path)) : null;
    $logoUrl = !empty($siteSettings['logo_path']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($siteSettings['logo_path']) : null;
    $faviconUrl = !empty($siteSettings['favicon_path']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($siteSettings['favicon_path']) : null;
    $visibleBlocks = collect($page->blocks ?? [])
        ->filter(fn ($block) => is_array($block) && ! (bool) ($block['hidden'] ?? false))
        ->filter(fn ($block) => ($block['type'] ?? null) !== 'text'
            || trim((string) ($block['data']['content'] ?? '')) !== '')
        ->values();
    $hasFullSiteMap = $visibleBlocks->contains(
        fn ($block) => ($block['type'] ?? null) === 'alert_map' && ($block['data']['layout'] ?? 'contained') === 'site',
    );
    $isFullSiteMapOnly = $hasFullSiteMap && $visibleBlocks->count() === 1 && ! $page->show_hero;
@endphp

<x-layouts.guest
    :title="$pageTitle.' — '.($siteSettings['site_name'] ?? 'SkyGuardian')"
    :meta-description="$description"
    :social-image="$socialImage"
    :language="$siteSettings['language'] ?? 'ru'"
    :favicon="$faviconUrl"
    :theme="$siteSettings['theme'] ?? 'classic'"
    :site-name="$siteSettings['site_name'] ?? 'SkyGuardian'"
    :canonical="($isNotFound ?? false) ? request()->url() : $page->publicUrl()"
    :robots="($isPreview ?? false) || ($isNotFound ?? false) ? 'noindex,nofollow' : 'index,follow,max-image-preview:large'"
    :public-page="!($isPreview ?? false)"
>
    <div class="site-shell">
        @if($isPreview ?? false)
            <div class="site-preview-bar">
                <strong>Предпросмотр страницы</strong>
                <span>Черновик виден только администратору.</span>
                <a href="{{ route('admin.site-settings.pages.edit', $page) }}">Вернуться к редактированию</a>
            </div>
        @endif

        <header class="site-header">
            <a class="site-brand" href="{{ url('/') }}" aria-label="На главную">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $siteSettings['site_name'] ?? 'SkyGuardian' }}">
                @else
                    <span class="site-brand-mark">SG</span>
                @endif
                <span>
                    <strong>{{ $siteSettings['site_name'] ?? 'SkyGuardian' }}</strong>
                    @if(!empty($siteSettings['site_tagline']))<small>{{ $siteSettings['site_tagline'] }}</small>@endif
                </span>
            </a>

            @if($siteMenu->isNotEmpty())
                <nav class="site-nav" aria-label="Основное меню">
                    @foreach($siteMenu as $item)
                        <div class="site-nav-item {{ $item->children->isNotEmpty() ? 'has-children' : '' }}">
                            <a href="{{ $item->resolvedUrl() }}" @if($item->open_in_new_tab) target="_blank" rel="noopener" @endif>
                                {{ $item->label }}
                            </a>
                            @if($item->children->isNotEmpty())
                                <div class="site-submenu">
                                    @foreach($item->children as $child)
                                        <a href="{{ $child->resolvedUrl() }}" @if($child->open_in_new_tab) target="_blank" rel="noopener" @endif>
                                            {{ $child->label }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </nav>
            @endif
        </header>

        <main @class([
            'site-main',
            'is-at-page-top' => ! $page->show_hero,
            'has-full-site-map' => $hasFullSiteMap,
            'is-full-site-map-only' => $isFullSiteMapOnly,
        ])>
            <article @class([
                'site-page',
                'has-full-site-map' => $hasFullSiteMap,
                'is-full-site-map-only' => $isFullSiteMapOnly,
            ])>
                @if($page->show_hero)
                    <header class="site-page-hero">
                        <p class="site-kicker">{{ $siteSettings['site_name'] ?? 'SkyGuardian' }}</p>
                        <h1>{{ $page->effectiveHeading() }}</h1>
                        @if($page->excerpt)<p class="site-page-excerpt">{{ $page->excerpt }}</p>@endif
                        @if($page->featured_image_path)
                            <img class="site-featured-image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($page->featured_image_path) }}" alt="{{ $page->effectiveHeading() }}">
                        @endif
                    </header>
                @endif

                <div @class([
                    'site-blocks',
                    'is-at-page-top' => ! $page->show_hero,
                    'has-full-site-map' => $hasFullSiteMap,
                    'is-full-site-map-only' => $isFullSiteMapOnly,
                ])>
                    @forelse($visibleBlocks as $block)
                        @include('public.partials.page-block', ['block' => $block])
                    @empty
                        <section class="site-empty-content">
                            <div class="site-empty-mark">SG</div>
                            <p>Содержимое страницы ещё не добавлено.</p>
                        </section>
                    @endforelse
                </div>
            </article>
        </main>

        <footer class="site-footer">
            <span>© {{ now()->year }} {{ $siteSettings['site_name'] ?? 'SkyGuardian' }}</span>
            @if(!empty($siteSettings['site_tagline']))<span>{{ $siteSettings['site_tagline'] }}</span>@endif
        </footer>
    </div>
</x-layouts.guest>
