<!DOCTYPE html>
<html lang="{{ $language ?? 'ru' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if(!empty($loginStyles))<meta name="csrf-token" content="{{ csrf_token() }}">@endif
    <title>{{ $title ?? 'SkyGuardian' }}</title>
    @if(!empty($metaDescription))<meta name="description" content="{{ $metaDescription }}">@endif
    <link rel="canonical" href="{{ $canonical ?? request()->url() }}">
    <meta property="og:title" content="{{ $title ?? 'SkyGuardian' }}">
    @if(!empty($metaDescription))<meta property="og:description" content="{{ $metaDescription }}">@endif
    @if(!empty($socialImage))<meta property="og:image" content="{{ $socialImage }}">@endif
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonical ?? request()->url() }}">
    <meta name="twitter:card" content="{{ !empty($socialImage) ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $title ?? 'SkyGuardian' }}">
    @if(!empty($metaDescription))<meta name="twitter:description" content="{{ $metaDescription }}">@endif
    @if(!empty($socialImage))<meta name="twitter:image" content="{{ $socialImage }}">@endif
    @if(!empty($favicon))<link rel="icon" href="{{ $favicon }}">@endif
    @vite(array_values(array_filter([
        'resources/css/app.css',
        !empty($loginStyles) ? 'resources/css/login-page.css' : 'resources/css/public-site.css',
        'resources/js/app.js',
    ])))
    @if(!empty($structuredData))
        <script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endif
</head>
<body class="sg-guest-body site-theme-{{ $theme ?? 'classic' }}">
    {{ $slot }}
    <x-toast />
</body>
</html>
