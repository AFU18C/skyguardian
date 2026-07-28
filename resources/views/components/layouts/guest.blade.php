<!DOCTYPE html>
<html lang="{{ $language ?? 'ru' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SkyGuardian' }}</title>
    @if(!empty($metaDescription))<meta name="description" content="{{ $metaDescription }}">@endif
    <meta property="og:title" content="{{ $title ?? 'SkyGuardian' }}">
    @if(!empty($metaDescription))<meta property="og:description" content="{{ $metaDescription }}">@endif
    @if(!empty($socialImage))<meta property="og:image" content="{{ $socialImage }}">@endif
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ request()->url() }}">
    @if(!empty($favicon))<link rel="icon" href="{{ $favicon }}">@endif
    @vite(['resources/css/app.css', 'resources/css/public-site.css', 'resources/js/app.js'])
</head>
<body class="sg-guest-body site-theme-{{ $theme ?? 'classic' }}">
    {{ $slot }}
    <x-toast />
</body>
</html>
