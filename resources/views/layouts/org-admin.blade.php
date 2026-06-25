<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="{{ ($organization->global_color_mode ?? 'dark') === 'dark' ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? __('navigation.org_admin') }} — {{ $organization->name }}</title>
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

        <script>
            var orgThemeKey = @json($organization->theme?->key) || @json($bpDefaultTheme);
            document.documentElement.dataset.bpTheme = localStorage.bpTheme || orgThemeKey;

            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && @json($organization->global_color_mode ?? 'dark') === 'dark')) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        </script>

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
            .dark[data-bp-theme="{{ $bpDefaultTheme }}"] {
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
        </style>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900">
        <div x-data="{ sidebarOpen: false, pinned: localStorage.getItem('org_admin_sidebar_pinned') === 'true', togglePin() { this.pinned = !this.pinned; localStorage.setItem('org_admin_sidebar_pinned', this.pinned); } }" class="flex min-h-screen">
            <!-- Overlay backdrop (mobile only) -->
            <div x-show="sidebarOpen" @click="sidebarOpen = false"
                 class="fixed inset-0 z-40 bg-black/50 lg:hidden"
                 x-transition.opacity>
            </div>

            <!-- Sidebar -->
            <aside :class="sidebarOpen ? '!flex !flex-col' : 'hidden'"
                   class="lg:flex lg:flex-col fixed inset-y-0 left-0 z-50 w-64 bg-gray-900 text-gray-200
                          transition-transform duration-300 ease-in-out
                          lg:translate-x-0 lg:static lg:w-60 lg:z-auto">
                <!-- Brand header -->
                <div class="px-5 py-5 border-b border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-lg font-bold text-white">{{ $organization->name }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded font-medium text-white" style="background-color: var(--bp-primary)">{{ __('navigation.org_admin_badge') }}</span>
                        </div>
                        <button @click="togglePin()"
                                :title="pinned ? 'Dépingler le menu' : 'Épingler le menu'"
                                class="p-1.5 rounded-lg transition hover:bg-gray-700 text-gray-400 hover:text-white"
                                :class="pinned ? '' : ''"
                                :style="pinned ? 'color: var(--bp-primary)' : ''">
                            <svg x-show="pinned" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            <svg x-show="!pinned" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1 truncate">{{ auth()->user()->name }}</p>
                    <div class="mt-2 flex items-center gap-0.5 rounded-full bg-gray-800 px-1 py-0.5 text-[10px] font-bold uppercase tracking-wide" aria-label="{{ __('navigation.language_switcher') }}">
                        @foreach(['en' => 'EN', 'fr' => 'FR'] as $locale => $label)
                            <form method="POST" action="{{ route('locale.switch', ['locale' => $locale]) }}" class="inline">
                                @csrf
                                <button type="submit"
                                    class="rounded-full px-1.5 py-0.5 transition {{ app()->getLocale() === $locale ? 'text-white shadow-sm' : 'text-gray-500 hover:text-white' }}"
                                    @if(app()->getLocale() === $locale) style="background-color: var(--bp-primary)" @endif
                                    aria-current="{{ app()->getLocale() === $locale ? 'true' : 'false' }}">
                                    {{ $label }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>

                <nav @click="if ($event.target.closest('a')) { pinned || (sidebarOpen = false) }" class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                    <!-- Voir l'organisation -->
                    <a href="{{ route('organization.home', ['organization' => $organization->slug]) }}"
                       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition mb-3
                              text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <span>{{ $organization->name }}</span>
                    </a>
                    @php
                        $isActive = fn($route) => request()->routeIs($route, $route.'.*');
                    @endphp

                    <!-- Dashboard -->
                    @php $active = $isActive('organization.admin.dashboard'); @endphp
                    <a href="{{ route('organization.admin.dashboard', ['organization' => $organization->slug]) }}"
                       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition mb-2
                               {{ $active ? 'text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}@if($active) style="background-color: var(--bp-primary)"@endif">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        {{ __('navigation.dashboard') }}
                    </a>

                    <!-- Section: Exchanges -->
                    <div class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('navigation.org_admin_section_exchanges') }}</div>

                    @foreach ([
                        ['route' => 'organization.admin.services', 'label' => __('navigation.org_admin_services'), 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
                        ['route' => 'organization.admin.requests', 'label' => __('navigation.org_admin_requests'), 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
                        ['route' => 'organization.admin.transactions', 'label' => __('navigation.org_admin_transactions'), 'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
                    ] as $item)
                        @php $active = $isActive($item['route']); @endphp
                        <a href="{{ route($item['route'], ['organization' => $organization->slug]) }}"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition
                                   {{ $active ? 'text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}@if($active) style="background-color: var(--bp-primary)"@endif">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    @endforeach

                    <!-- Section: Content -->
                    <div class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('navigation.org_admin_section_content') }}</div>

                    @foreach ([
                        ['route' => 'organization.admin.blog', 'label' => __('navigation.org_admin_blog'), 'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z'],
                        ['route' => 'organization.admin.categories', 'label' => __('navigation.org_admin_categories'), 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
                    ] as $item)
                        @php $active = $isActive($item['route']); @endphp
                        <a href="{{ route($item['route'], ['organization' => $organization->slug]) }}"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition
                                   {{ $active ? 'text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}@if($active) style="background-color: var(--bp-primary)"@endif">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    @endforeach

                    <!-- Section: Community -->
                    <div class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('navigation.org_admin_section_community') }}</div>

                    @foreach ([
                        ['route' => 'organization.admin.loops', 'label' => __('navigation.org_admin_loops'), 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z'],
                        ['route' => 'organization.admin.messages', 'label' => __('navigation.org_admin_messages'), 'icon' => 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z'],
                        ['route' => 'organization.admin.users', 'label' => __('navigation.org_admin_users'), 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z'],
                    ] as $item)
                        @php $active = $isActive($item['route']); @endphp
                        <a href="{{ route($item['route'], ['organization' => $organization->slug]) }}"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition
                                   {{ $active ? 'text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}@if($active) style="background-color: var(--bp-primary)"@endif">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    @endforeach

                    <!-- Section: Administration -->
                    <div class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('navigation.org_admin_section_administration') }}</div>

                    @foreach ([
                        ['route' => 'organization.admin.identity', 'label' => __('navigation.org_admin_identity'), 'icon' => 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
                        ['route' => 'organization.admin.reports', 'label' => __('navigation.org_admin_reports'), 'icon' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                        ['route' => 'organization.admin.invitations', 'label' => __('navigation.org_admin_invitations'), 'icon' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2zM15 11a3 3 0 11-6 0 3 3 0 016 0z'],
                        ['route' => 'organization.admin.translations', 'label' => __('navigation.org_admin_translations'), 'icon' => 'M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129'],
                    ] as $item)
                        @php $active = $isActive($item['route']); @endphp
                        <a href="{{ route($item['route'], ['organization' => $organization->slug]) }}"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition
                                   {{ $active ? 'text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}@if($active) style="background-color: var(--bp-primary)"@endif">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    @endforeach

                    <!-- Design / themes (à venir) -->
                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-600 mb-2 mt-4 cursor-not-allowed">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828L10.828 18.83"/>
                        </svg>
                        <span class="text-gray-500 italic text-xs">{{ __('navigation.org_admin_coming_soon', ['section' => __('navigation.org_admin_design')]) }}</span>
                    </div>

                    <!-- IA group -->
                    <div class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('navigation.org_admin_ia') }}</div>

                    @foreach ([
                        ['route' => 'organization.admin.ai-supervision', 'label' => __('navigation.org_admin_ai_supervision'), 'icon' => 'M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z'],
                        ['route' => 'organization.admin.member-ai-profiles', 'label' => __('navigation.org_admin_member_ai_profiles'), 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zm-4 7a5 5 0 00-5 5h10a5 5 0 00-5-5z'],
                        ['route' => 'organization.admin.ai-interactions', 'label' => __('navigation.org_admin_ai_interactions'), 'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
                    ] as $item)
                        @php $active = $isActive($item['route']); @endphp
                        <a href="{{ route($item['route'], ['organization' => $organization->slug]) }}"
                           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition
                                   {{ $active ? 'text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}@if($active) style="background-color: var(--bp-primary)"@endif">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>

                <!-- Retour à l'organisation -->
                <div class="px-3 pb-4 border-t border-gray-700 pt-3 space-y-1">
                    <a href="{{ route('organization.home', ['organization' => $organization->slug]) }}"
                       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
                        {{ __('navigation.org_admin_back_to_org') }}
                    </a>
                </div>
            </aside>

            <!-- Main content -->
            <div class="flex-1 flex flex-col min-w-0">
                <!-- Top bar -->
                <header class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg focus:outline-none focus:ring-2" style="--tw-ring-color: var(--bp-primary)">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>
                        <h1 class="font-semibold text-gray-900 dark:text-gray-100">{{ $title ?? __('navigation.org_admin') }}</h1>
                    </div>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ now()->format('d/m/Y') }}</span>
                </header>

                <main class="flex-1 p-6 overflow-auto">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <!-- Toast notifications -->
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
            <div class="flex items-center gap-3 text-white px-4 py-3 rounded-xl" style="background-color: var(--bp-primary)">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-sm font-medium flex-1">{{ session('info') }}</p>
                <button @click="show = false" class="opacity-70 hover:opacity-100 text-xl leading-none">&times;</button>
            </div>
            @endif
        </div>
        @endif

        @stack('scripts')
        @livewireScripts
    </body>
</html>
