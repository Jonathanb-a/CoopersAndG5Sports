<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $meta['title'] ?? config('app.name', 'Laravel') }}</title>

        @isset($meta)
            {{-- Para compartir en redes: los rastreadores leen el HTML, no ejecutan JavaScript. --}}
            <meta name="description" content="{{ $meta['description'] }}">
            <meta property="og:type" content="{{ $meta['type'] ?? 'product' }}">
            <meta property="og:locale" content="es_MX">
            <meta property="og:site_name" content="{{ $meta['site_name'] }}">
            <meta property="og:title" content="{{ $meta['title'] }}">
            <meta property="og:description" content="{{ $meta['description'] }}">
            @if (! empty($meta['url']))
                <meta property="og:url" content="{{ $meta['url'] }}">
            @endif
            @if (! empty($meta['image']))
                <meta property="og:image" content="{{ $meta['image'] }}">
                <meta name="twitter:card" content="summary_large_image">
                <meta name="twitter:image" content="{{ $meta['image'] }}">
            @endif
            <meta name="twitter:title" content="{{ $meta['title'] }}">
            <meta name="twitter:description" content="{{ $meta['description'] }}">
            @if (! empty($meta['url']))
                <link rel="canonical" href="{{ $meta['url'] }}">
            @endif
        @endisset

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
