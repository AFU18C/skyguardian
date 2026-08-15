<!DOCTYPE html>
<html lang="{{ $language ?? 'ru' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if(!($publicPage ?? false))<meta name="csrf-token" content="{{ csrf_token() }}">@endif
    <title>{{ $title ?? 'SkyGuardian' }}</title>
    @if(!empty($metaDescription))<meta name="description" content="{{ $metaDescription }}">@endif
    <meta name="robots" content="{{ $robots ?? 'noindex,nofollow' }}">
    <link rel="canonical" href="{{ $canonical ?? request()->url() }}">
    <meta property="og:title" content="{{ $title ?? 'SkyGuardian' }}">
    @if(!empty($metaDescription))<meta property="og:description" content="{{ $metaDescription }}">@endif
    @if(!empty($socialImage))<meta property="og:image" content="{{ $socialImage }}">@endif
    @if(!empty($socialImage))<meta property="og:image:alt" content="{{ $title ?? 'SkyGuardian' }}">@endif
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonical ?? request()->url() }}">
    <meta property="og:site_name" content="{{ $siteName ?? 'SkyGuardian' }}">
    <meta property="og:locale" content="{{ ($language ?? 'ru') === 'uk' ? 'uk_UA' : 'ru_UA' }}">
    <meta name="twitter:card" content="{{ !empty($socialImage) ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $title ?? 'SkyGuardian' }}">
    @if(!empty($metaDescription))<meta name="twitter:description" content="{{ $metaDescription }}">@endif
    @if(!empty($socialImage))<meta name="twitter:image" content="{{ $socialImage }}">@endif
    @if(!empty($favicon))<link rel="icon" href="{{ $favicon }}">@endif
    @vite(['resources/css/app.css', 'resources/css/public-site.css', 'resources/css/login-page.css', 'resources/js/app.js'])
</head>
<body class="sg-guest-body site-theme-{{ $theme ?? 'classic' }}">
    {{ $slot }}
    @if(!($publicPage ?? false))<x-toast />@endif
</body>
</html>
