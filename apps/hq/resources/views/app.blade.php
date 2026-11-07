<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
        <link rel="preconnect" href="https://fonts.bunny.net">
        {{-- docs/135 (d): Geist for Latin, Geist Mono for codes and figures, Noto Sans Bengali for Bangla. --}}
        <link href="https://fonts.bunny.net/css?family=geist:400,500,600|geist-mono:400,500|noto-sans-bengali:400,500,600&display=swap" rel="stylesheet" />

        {{-- Applies the saved light or dark choice before the first paint, so a dark-theme page never flashes white.
             The same rule as resources/js/hooks/use-appearance.tsx, which takes over once the app boots. --}}
        <script>
            (function () {
                try {
                    var a = localStorage.getItem('appearance') || 'system';
                    var dark = a === 'dark' || (a === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
