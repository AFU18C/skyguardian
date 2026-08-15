@php($siteSettings ??= app(\App\Services\SiteContentService::class)->settings())
<x-layouts.guest
    :title="($siteSettings['site_name'] ?? 'SkyGuardian').' — '.($siteSettings['site_tagline'] ?? 'Система мониторинга информации')"
    :meta-description="$siteSettings['site_tagline'] ?? 'Система мониторинга информации'"
    :site-name="$siteSettings['site_name'] ?? 'SkyGuardian'"
    :language="$siteSettings['language'] ?? 'ru'"
    :canonical="url('/')"
    robots="index,follow,max-image-preview:large"
    public-page
>
    <main class="sg-public-page">
        <div class="sg-public-grid"></div>
        <section class="sg-public-card">
            <div class="sg-public-emblem">SG</div>
            <p class="sg-eyebrow">{{ $siteSettings['site_tagline'] ?? 'Система мониторинга информации' }}</p>
            <h1>{{ $siteSettings['site_name'] ?? 'SkyGuardian' }}</h1>
            <p class="sg-public-lead">Сайт находится в разработке</p>
            <p class="sg-public-copy">Публичная часть системы будет доступна после завершения настройки.</p>
            <a class="sg-button sg-button-primary" href="{{ route('admin.login') }}">Войти в админку</a>
        </section>
    </main>
</x-layouts.guest>
