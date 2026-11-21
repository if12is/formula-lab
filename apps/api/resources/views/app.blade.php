@php
    $locale = $page['props']['locale'];
    $theme = $page['props']['theme']['preference'];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale['current'] }}" dir="{{ $locale['direction'] }}"
      data-theme-source="server"
      data-mode="{{ $theme === 'system' ? 'light' : $theme }}"
      data-theme-preference="{{ $theme }}"
      data-accent="leaf"
      data-reading="standard">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'Formula Lab') }}</title>
        {{-- Paint the "system" preference before the first frame; light and dark are already on <html>. --}}
        <script>
            (function () {
                var d = document.documentElement;
                if (d.dataset.themePreference === 'system') {
                    d.dataset.mode = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
            })();
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:wght@500;600;700;800&family=Lato:ital,wght@0,300;0,400;0,700;0,900;1,400&family=Geist+Mono:wght@400;500;600&family=Audiowide&family=Almarai:wght@300;400;700;800&family=Tajawal:wght@300;400;500;700;800&family=Atkinson+Hyperlegible+Next:wght@400;700&display=swap">
        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
