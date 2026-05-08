<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="{{ !isset($currentCommunity) && ($globalColorMode ?? 'dark') === 'dark' ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title . ' — ' : '' }}{{ config('app.name', 'Entraide') }}</title>
        <meta name="description" content="{{ isset($description) ? $description : 'Plateforme de troc de services entre professionnels — échangez vos compétences sans argent.' }}">

        @isset($ogTitle)
        <meta property="og:title" content="{{ $ogTitle }}">
        <meta property="og:description" content="{{ $ogDescription ?? '' }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ url()->current() }}">
        @if(!empty($ogImage))
        <meta property="og:image" content="{{ $ogImage }}">
        @endif
        @endisset
        @isset($jsonLd)
        <script type="application/ld+json">{!! $jsonLd !!}</script>
        @endisset

        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        <link rel="shortcut icon" href="/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-title" content="BouclePro" />
        <link rel="manifest" href="/site.webmanifest" />

        <!-- Fonts -->
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
    <body class="font-sans antialiased text-zinc-900 dark:text-zinc-100 selection:bg-indigo-100 dark:selection:bg-indigo-500/30">
        <div class="min-h-screen flex flex-col bg-white dark:bg-zinc-950">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white/50 dark:bg-zinc-900/50 backdrop-blur-md border-b border-zinc-100 dark:border-zinc-800">
                    <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="flex-1">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </main>

            @include('partials.footer')
        </div>

        <!-- Toast notifications globales -->
        @if(session('success') || session('error') || session('info'))
        <div x-data="{ show: true }" x-show="show"
             x-init="setTimeout(() => show = false, 4500)"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-3"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-3"
             class="fixed bottom-6 right-6 z-50 max-w-sm w-full"
             x-cloak>
            <div class="rounded-2xl border backdrop-blur-md shadow-2xl p-4 flex items-center gap-4
                {{ session('success') ? 'bg-emerald-50/90 dark:bg-emerald-500/10 border-emerald-100 dark:border-emerald-500/20 text-emerald-800 dark:text-emerald-400' : '' }}
                {{ session('error') ? 'bg-rose-50/90 dark:bg-rose-500/10 border-rose-100 dark:border-rose-500/20 text-rose-800 dark:text-rose-400' : '' }}
                {{ session('info') ? 'bg-indigo-50/90 dark:bg-indigo-500/10 border-indigo-100 dark:border-indigo-500/20 text-indigo-800 dark:text-indigo-400' : '' }}
            ">
                <div class="shrink-0">
                    @if(session('success'))
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    @elseif(session('error'))
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    @elseif(session('info'))
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @endif
                </div>
                <p class="text-sm font-bold tracking-tight flex-1">{{ session('success') ?: (session('error') ?: session('info')) }}</p>
                <button @click="show = false" class="shrink-0 p-1 rounded-lg hover:bg-white/50 dark:hover:bg-black/20 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        @endif

        @stack('scripts')

        <!-- Alpine store global pour les modals de confirmation -->
        <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('modal', {
                active: null,
                _form: null,
                open(id, form) { this.active = id; this._form = form; },
                close() { this.active = null; this._form = null; },
                confirm() { if (this._form) this._form.submit(); this.close(); }
            });
        });
        </script>
    </body>
</html>
