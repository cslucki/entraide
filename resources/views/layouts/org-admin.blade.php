<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? __('navigation.org_admin') }} — {{ config('app.name', 'Entraide') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
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
                            <span class="text-xs bg-purple-600 text-white px-1.5 py-0.5 rounded font-medium">{{ __('navigation.org_admin_badge') }}</span>
                        </div>
                        <button @click="togglePin()"
                                :title="pinned ? 'Dépingler le menu' : 'Épingler le menu'"
                                class="p-1.5 rounded-lg transition hover:bg-gray-700 text-gray-400 hover:text-white"
                                :class="pinned ? 'text-indigo-400' : ''">
                            <svg x-show="pinned" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            <svg x-show="!pinned" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1 truncate">{{ auth()->user()->name }}</p>
                </div>

                <nav @click="if ($event.target.closest('a')) { pinned || (sidebarOpen = false) }" class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                    @php
                        $isActive = fn($route) => request()->routeIs($route, $route.'.*');
                    @endphp

                    <!-- Dashboard -->
                    @php $active = $isActive('organization.admin.dashboard'); @endphp
                    <a href="{{ route('organization.admin.dashboard', ['organization' => $organization->slug]) }}"
                       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition mb-2
                               {{ $active ? 'bg-purple-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        {{ __('navigation.dashboard') }}
                    </a>

                    <!-- Design / themes (à venir) -->
                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-600 mb-2 cursor-not-allowed">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828L10.828 18.83"/>
                        </svg>
                        <span class="text-gray-500 italic text-xs">{{ __('navigation.org_admin_coming_soon', ['section' => __('navigation.org_admin_design')]) }}</span>
                    </div>

                    <!-- Échanges group (à venir) -->
                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-600 mb-2 cursor-not-allowed">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span class="text-gray-500 italic text-xs">{{ __('navigation.org_admin_coming_soon', ['section' => __('navigation.org_admin_exchanges')]) }}</span>
                    </div>

                    <!-- Organisation group (à venir) -->
                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-600 mb-2 cursor-not-allowed">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857A17.983 17.983 0 0112 16c-2.071 0-4.065.332-5.932.943A3 3 0 001 20h5v2a3 3 0 005.356 1.857A17.983 17.983 0 0112 18c2.071 0 4.065.332 5.932.943A3 3 0 0017 20z"/>
                        </svg>
                        <span class="text-gray-500 italic text-xs">{{ __('navigation.org_admin_coming_soon', ['section' => __('navigation.org_admin_organization')]) }}</span>
                    </div>

                    <!-- IA group (à venir) -->
                    <div class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-600 mb-2 cursor-not-allowed">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                        <span class="text-gray-500 italic text-xs">{{ __('navigation.org_admin_coming_soon', ['section' => __('navigation.org_admin_ia')]) }}</span>
                    </div>
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
                        <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
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
            <div class="flex items-center gap-3 bg-indigo-600 text-white px-4 py-3 rounded-xl">
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
