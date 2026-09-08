<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">

    {{--
        CSRF for every axios/fetch call the app will ever make. Livewire reads
        its own token; this covers plain JS (§75).
    --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--
        robots: this platform holds student records behind auth. A staging deploy
        that is accidentally indexable would leak personal data into a search
        engine, so the default is noindex unless APP_ENV says otherwise.
    --}}
    @if (! app()->environment('production') || config('platform.meta.noindex', false))
        <meta name="robots" content="noindex, nofollow">
    @endif

    <title>@yield('title', config('platform.name', 'EduPlatform'))</title>
    <meta name="description" content="@yield('description', config('platform.name', 'EduPlatform'))">

    {{--
        Applied before first paint: reading localStorage after the stylesheet
        loads means a dark-mode user sees a white flash on every navigation.
    --}}
    <script>
        (function () {
            try {
                var p = localStorage.getItem('platform-theme') || 'system';
                var dark = p === 'dark' || (p === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (dark) {
                    document.documentElement.classList.add('dark');
                    document.documentElement.style.colorScheme = 'dark';
                }
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="min-h-full font-sans antialiased">
    {{--
        A "skip to content" link is the first focusable element. For a keyboard
        user on a page with a nine-item portal nav, it is the difference between
        usable and unusable.
    --}}
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">
        Skip to main content
    </a>

    @yield('body')

    {{--
        Session-level notices are rendered here rather than per-page so an
        authorization failure or a payment confirmation cannot be lost because a
        view forgot to include the component.
    --}}
    <x-flash-messages />

    @stack('scripts')
</body>
</html>
