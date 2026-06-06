<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#1B1FCC">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Entraide') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="apple-mobile-web-app-title" content="BouclePro" />
        <link rel="manifest" href="/site.webmanifest" />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        <script>
            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-900">
        <div class="min-h-screen flex flex-col">

            <!-- Header avec logo -->
            <div class="flex justify-center pt-10 pb-6">
                <a href="{{ route('home') }}" class="flex items-center gap-3 group">
                    <img src="/favicon.svg" alt="{{ $platformName ?? config('app.name') }}" class="h-10 w-10">
                    <div class="text-left">
                        <div class="text-xl font-bold text-gray-900 dark:text-white leading-tight group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition">
                            {{ $platformName ?? config('app.name') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 leading-tight">
                            {{ $platformTagline ?? 'Échangez vos talents' }}
                        </div>
                    </div>
                </a>
            </div>

            <!-- Carte du formulaire -->
            <div class="flex-1 flex flex-col items-center px-4 pb-12">
                <div class="w-full max-w-md bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden border border-gray-100 dark:border-gray-700">
                    {{ $slot }}
                </div>
            </div>

            @include('partials.footer')
        </div>
    </body>
</html>
