<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
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
             class="fixed bottom-5 right-5 z-50 max-w-sm w-full shadow-xl"
             x-cloak>
            @if(session('success'))
            <div class="flex items-center gap-3 bg-green-600 text-white px-4 py-3 rounded-xl">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <p class="text-sm font-medium flex-1">{{ session('success') }}</p>
                <button @click="show = false" class="opacity-70 hover:opacity-100 text-xl leading-none">&times;</button>
            </div>
            @elseif(session('error'))
            <div class="flex items-center gap-3 bg-red-600 text-white px-4 py-3 rounded-xl">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <p class="text-sm font-medium flex-1">{{ session('error') }}</p>
                <button @click="show = false" class="opacity-70 hover:opacity-100 text-xl leading-none">&times;</button>
            </div>
            @elseif(session('info'))
            <div class="flex items-center gap-3 bg-indigo-600 text-white px-4 py-3 rounded-xl">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-sm font-medium flex-1">{{ session('info') }}</p>
                <button @click="show = false" class="opacity-70 hover:opacity-100 text-xl leading-none">&times;</button>
            </div>
            @endif
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
