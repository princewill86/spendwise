<!DOCTYPE html>
<html data-personal-theme="{{ data_get($page, 'props.platform.personalTheme') }}" data-default-theme="{{ data_get($page, 'props.platform.personalTheme') ?? data_get($page, 'props.platform.theme', 'system') }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if(data_get($page, 'props.platform.favicon'))
        <link rel="icon" href="{{ data_get($page, 'props.platform.favicon') }}">
    @endif
    <meta name="color-scheme" content="light dark">
    @if(request()->routeIs('home', 'content', 'custom-content'))
        <title data-inertia="">{{ data_get($page, 'props.seoTitle', 'Spendwise') }} | Spendwise</title>
        <meta data-inertia="description" name="description" content="{{ data_get($page, 'props.description') }}">
        <link data-inertia="canonical" rel="canonical" href="{{ data_get($page, 'props.canonical') }}">
        <meta data-inertia="og-title" property="og:title" content="{{ data_get($page, 'props.seoTitle') }}">
        <meta data-inertia="og-description" property="og:description" content="{{ data_get($page, 'props.description') }}">
        <meta data-inertia="og-url" property="og:url" content="{{ data_get($page, 'props.canonical') }}">
        @if(data_get($page, 'props.image'))
            <meta data-inertia="og-image" property="og:image" content="{{ data_get($page, 'props.image') }}">
        @endif
    @else
        <meta name="robots" content="noindex, nofollow">
    @endif
    <script>
        try {
            document.documentElement.dataset.theme = document.documentElement.dataset.personalTheme || localStorage.getItem('spendwise-theme') || document.documentElement.dataset.defaultTheme || 'system';
        } catch {
            document.documentElement.dataset.theme = document.documentElement.dataset.defaultTheme || 'system';
        }
    </script>
    @vite('resources/js/app.ts')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
