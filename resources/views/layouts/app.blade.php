<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="{{ ($globalColorMode ?? 'dark') === 'dark' ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#1B1FCC">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @auth
        <meta name="user-id" content="{{ auth()->id() }}">
        @endauth

        <title>{{ isset($title) && filled($title) ? $title . ' — ' : '' }}{{ config('app.name', 'Entraide') }}</title>
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

        <!-- Favicon BouclePro -->
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png" />
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
        <link rel="shortcut icon" href="/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-title" content="BouclePro" />
        <link rel="manifest" href="/site.webmanifest" />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @php
            $cachePath = storage_path('app/bouclepro-themes.php');

            if (file_exists($cachePath)) {
                $bpThemes = require $cachePath;
                $bpDefaultTheme = $bpThemes['_meta']['default'] ?? config('bouclepro_themes.default', 'zen');
                unset($bpThemes['_meta']);
            } else {
                $bpThemes = config('bouclepro_themes.themes');
                $bpDefaultTheme = config('bouclepro_themes.default', 'zen');
            }
        @endphp

        <!-- Scripts -->
        <script>
            window.bpThemes = @json(collect($bpThemes)->map(fn ($theme) => ['label' => $theme['label']])->all());
            window.bpDefaultTheme = @json($bpDefaultTheme);
            var orgThemeKey = @json(optional($currentOrganization ?? null)?->theme?->key) || window.bpDefaultTheme;
            document.documentElement.dataset.bpTheme = localStorage.bpTheme || orgThemeKey;

            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && @json($globalColorMode ?? 'dark') === 'dark')) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @php
            $org = app()->bound('current_organization') ? app('current_organization') : null;
        @endphp
        @if($org && $org->header_javascript_enabled && $org->header_javascript)
            {!! $org->header_javascript !!}
        @endif

        @stack('head')

        <style>
            :root {
                @foreach($bpThemes[$bpDefaultTheme]['tokens'] as $token => $value)
                --bp-{{ $token }}: {{ $value }};
                @endforeach
            }

            @foreach($bpThemes as $key => $theme)
            [data-bp-theme="{{ $key }}"] {
                @foreach($theme['tokens'] as $token => $value)
                --bp-{{ $token }}: {{ $value }};
                @endforeach
            }
            @endforeach

            .dark {
                @foreach($bpThemes[$bpDefaultTheme]['dark'] as $token => $value)
                --bp-{{ $token }}: {{ $value }};
                @endforeach
            }

            @foreach($bpThemes as $key => $theme)
            .dark[data-bp-theme="{{ $key }}"] {
                @foreach($theme['dark'] as $token => $value)
                --bp-{{ $token }}: {{ $value }};
                @endforeach
            }
            @endforeach

            /* Mobile safe areas */
            .mobile-safe-top { padding-top: 0; }
            .mobile-safe-bottom-auth { padding-bottom: 0; }
            @media (max-width: 767px) {
                .mobile-safe-top { padding-top: calc(3.5rem + env(safe-area-inset-top, 0px)); }
                .mobile-safe-bottom-auth { padding-bottom: calc(4rem + env(safe-area-inset-bottom, 0px)); }
            }
        </style>
    </head>
    <body class="font-sans antialiased">
        {{-- Admin impersonation banner --}}
        @if(session('admin_original_id'))
        <div class="bg-amber-500 text-amber-950 px-4 py-2 text-sm font-medium flex items-center justify-center gap-3">
            <span>Connecté sous <strong>{{ auth()->user()->full_name }}</strong> (mode admin)</span>
            <a href="{{ route('admin.back-to-admin') }}"
               class="inline-flex items-center gap-1 px-3 py-1 bg-amber-700 text-white rounded-lg text-xs font-semibold hover:bg-amber-800 transition">
                Retour au compte admin
            </a>
        </div>
        @endif

        {{-- Mobile shell (hidden md:block) --}}
        <x-mobile-topbar title="{{ isset($title) && filled($title) ? $title : config('app.name') }}" :brand-name="$brandOrganizationName ?? null" />
        <x-mobile-bottom-nav />
        <x-mobile-fab />

        <x-app-side-nav />

        <div class="min-h-screen flex flex-col bg-[var(--bp-page)] pt-0 md:pl-20 pb-0 md:pb-0 mobile-safe-top mobile-safe-bottom-auth">

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="flex-1 md:min-h-screen">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </main>

            
        </div>
        <!-- Toast notifications globales -->
        @if((session('success') && session('success') !== 'Message envoyé.') || session('error') || session('info'))
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

        <livewire:styles />

        <livewire:scripts />
    </body>
</html>
