<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? 'Administration' }} — {{ config('app.name', 'Entraide') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900">
        <div x-data="{ sidebarOpen: false, pinned: localStorage.getItem('admin_sidebar_pinned') === 'true', togglePin() { this.pinned = !this.pinned; localStorage.setItem('admin_sidebar_pinned', this.pinned); } }" class="flex min-h-screen">
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
                        <a href="{{ route('home') }}" class="flex items-center gap-2">
                            <span class="text-lg font-bold text-white">Entraide</span>
                            <span class="text-xs bg-red-600 text-white px-1.5 py-0.5 rounded font-medium">Admin</span>
                        </a>
                        <button @click="togglePin()"
                                :title="pinned ? 'Désépingler le menu' : 'Épingler le menu'"
                                class="p-1.5 rounded-lg transition hover:bg-gray-700 text-gray-400 hover:text-white"
                                :class="pinned ? 'text-indigo-400' : ''">
                            <svg x-show="pinned" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            <svg x-show="!pinned" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1 truncate">{{ auth()->user()->name }}</p>
                </div>

                <nav @click="pinned || (sidebarOpen = false)" class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                    @php
                        $isActive = fn($route) => request()->routeIs($route);
                        $isGroupActive = fn($items) => collect($items)->contains(fn($i) => $isActive($i['route']));

                        $emailItems = [
                            ['route' => 'admin.email-templates', 'label' => 'Templates', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
                            ['route' => 'admin.email-logs', 'label' => 'Historique', 'icon' => 'M3 19v-8.93a2 2 0 01.89-1.664l7-4.666a2 2 0 012.22 0l7 4.666A2 2 0 0121 10.07V19M3 19a2 2 0 002 2h14a2 2 0 002-2M3 19l6.75-4.5M21 19l-6.75-4.5M3 10l6.75 4.5M21 10l-6.75 4.5m0 4.5v-4.5m0 4.5l-6.75-4.5M21 10l-6.75 4.5'],
                            ['route' => 'admin.email-test', 'label' => 'Test', 'icon' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                        ];
                        $echangesItems = [
                            ['route' => 'admin.services', 'label' => 'Services', 'icon' => 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                            ['route' => 'admin.transactions', 'label' => 'Transactions', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
                            ['route' => 'admin.requests', 'label' => 'Demandes', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                            ['route' => 'admin.loops', 'label' => 'Boucles', 'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4'],
                            ['route' => 'admin.messages', 'label' => 'Messages', 'icon' => 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z'],
                            ['route' => 'admin.blog', 'label' => 'Blog', 'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z'],
                        ];
                        $orgItems = [
                            ['route' => 'admin.organizations', 'label' => 'Organisations', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857A17.983 17.983 0 0112 16c-2.071 0-4.065.332-5.932.943A3 3 0 001 20h5v2a3 3 0 005.356 1.857A17.983 17.983 0 0112 18c2.071 0 4.065.332 5.932.943A3 3 0 0017 20z'],
                            ['route' => 'admin.meta-organization', 'label' => 'Meta-Organisation', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                            ['route' => 'admin.settings', 'label' => 'Paramètres', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                            ['route' => 'admin.categories', 'label' => 'Catégories', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
                            ['route' => 'admin.users', 'label' => 'Utilisateurs', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                            ['route' => 'admin.reports', 'label' => 'Signalements', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
                            ['route' => 'admin.referrals', 'label' => 'Invitations', 'icon' => 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z'],
                        ];
                    @endphp

                    <!-- Tableau de bord (standalone) -->
                    @php $active = $isActive('admin.dashboard'); @endphp
                    <a href="{{ route('admin.dashboard') }}"
                       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition mb-2
                              {{ $active ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        Tableau de bord
                    </a>

                    <!-- Email group -->
                    @php $groupActive = $isGroupActive($emailItems); @endphp
                    <div x-data="{ open: true }">
                        <button @click="open = !open"
                                class="flex items-center gap-2 w-full px-3 py-2 rounded-lg text-sm transition text-left
                                       {{ $groupActive ? 'text-indigo-400' : 'text-gray-500 hover:text-gray-300' }}">
                            <svg class="w-3 h-3 transition-transform duration-200 flex-shrink-0"
                                 :class="{'rotate-180': !open}"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                            <span class="text-xs font-semibold uppercase tracking-wider">Email</span>
                        </button>
                        <div x-show="open"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-y-95"
                             x-transition:enter-end="opacity-100 scale-y-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 scale-y-100"
                             x-transition:leave-end="opacity-0 scale-y-95"
                             class="origin-top">
                            @foreach($emailItems as $item)
                            @php $itemActive = $isActive($item['route']); @endphp
                            <a href="{{ route($item['route']) }}"
                               class="flex items-center gap-3 px-3 py-2 pl-7 rounded-lg text-sm transition
                                      {{ $itemActive ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                                </svg>
                                {{ $item['label'] }}
                            </a>
                            @endforeach
                        </div>
                    </div>

                    <!-- Échanges group -->
                    @php $groupActive = $isGroupActive($echangesItems); @endphp
                    <div x-data="{ open: true }">
                        <button @click="open = !open"
                                class="flex items-center gap-2 w-full px-3 py-2 rounded-lg text-sm transition text-left
                                       {{ $groupActive ? 'text-indigo-400' : 'text-gray-500 hover:text-gray-300' }}">
                            <svg class="w-3 h-3 transition-transform duration-200 flex-shrink-0"
                                 :class="{'rotate-180': !open}"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                            <span class="text-xs font-semibold uppercase tracking-wider">Échanges</span>
                        </button>
                        <div x-show="open"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-y-95"
                             x-transition:enter-end="opacity-100 scale-y-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 scale-y-100"
                             x-transition:leave-end="opacity-0 scale-y-95"
                             class="origin-top">
                            @foreach($echangesItems as $item)
                            @php $itemActive = $isActive($item['route']); @endphp
                            <a href="{{ route($item['route']) }}"
                               class="flex items-center gap-3 px-3 py-2 pl-7 rounded-lg text-sm transition
                                      {{ $itemActive ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                                </svg>
                                {{ $item['label'] }}
                            </a>
                            @endforeach
                        </div>
                    </div>

                    <!-- Organisations group -->
                    @php $groupActive = $isGroupActive($orgItems); @endphp
                    <div x-data="{ open: true }">
                        <button @click="open = !open"
                                class="flex items-center gap-2 w-full px-3 py-2 rounded-lg text-sm transition text-left
                                       {{ $groupActive ? 'text-indigo-400' : 'text-gray-500 hover:text-gray-300' }}">
                            <svg class="w-3 h-3 transition-transform duration-200 flex-shrink-0"
                                 :class="{'rotate-180': !open}"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                            <span class="text-xs font-semibold uppercase tracking-wider">Organisations</span>
                        </button>
                        <div x-show="open"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-y-95"
                             x-transition:enter-end="opacity-100 scale-y-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 scale-y-100"
                             x-transition:leave-end="opacity-0 scale-y-95"
                             class="origin-top">
                            @foreach($orgItems as $item)
                            @php $itemActive = $isActive($item['route']); @endphp
                            <a href="{{ route($item['route']) }}"
                               class="flex items-center gap-3 px-3 py-2 pl-7 rounded-lg text-sm transition
                                      {{ $itemActive ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                                </svg>
                                {{ $item['label'] }}
                                @if($item['route'] === 'admin.reports' && ($pendingReportsCount ?? 0) > 0)
                                <span class="ml-auto bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center font-bold">{{ $pendingReportsCount }}</span>
                                @endif
                            </a>
                            @endforeach
                        </div>
                    </div>

                    <!-- IA group -->
                    @php
                        $iaItems = [];
                        if (!app()->isProduction()) {
                            $iaItems[] = ['route' => 'admin.ia-design-lab', 'label' => 'Lab IA', 'icon' => 'M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z'];
                        }
                        $iaItems[] = ['route' => 'admin.ai-supervision', 'label' => 'Supervision IA', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'];
                        $iaGroupActive = $isGroupActive($iaItems);
                    @endphp
                    <div x-data="{ open: true }">
                        <button @click="open = !open"
                                class="flex items-center gap-2 w-full px-3 py-2 rounded-lg text-sm transition text-left
                                       {{ $iaGroupActive ? 'text-indigo-400' : 'text-gray-500 hover:text-gray-300' }}">
                            <svg class="w-3 h-3 transition-transform duration-200 flex-shrink-0"
                                 :class="{'rotate-180': !open}"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                            <span class="text-xs font-semibold uppercase tracking-wider">IA</span>
                        </button>
                        <div x-show="open"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-y-95"
                             x-transition:enter-end="opacity-100 scale-y-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 scale-y-100"
                             x-transition:leave-end="opacity-0 scale-y-95"
                             class="origin-top">
                            @foreach($iaItems as $item)
                            @php $itemActive = $isActive($item['route']); @endphp
                            <a href="{{ route($item['route']) }}"
                               class="flex items-center gap-3 px-3 py-2 pl-7 rounded-lg text-sm transition
                                      {{ $itemActive ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                                </svg>
                                {{ $item['label'] }}
                            </a>
                            @endforeach
                        </div>
                    </div>
                </nav>

                <!-- Retour à l'app -->
                @php
                    $returnOrg = auth()->user()->organization;
                    $returnUrl = $returnOrg
                        ? route('organization.dashboard', ['organization' => $returnOrg->slug])
                        : url('/dashboard');
                @endphp
                <div class="px-3 pb-4 border-t border-gray-700 pt-3 space-y-1">
                    <a href="{{ $returnUrl }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-400 hover:text-white hover:bg-gray-800 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
                        Retour à l'app
                    </a>
                </div>
            </aside>

            <!-- Main content -->
            <div class="flex-1 flex flex-col min-w-0">
                <!-- Top bar -->
                <header class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>
                        <h1 class="font-semibold text-gray-900 dark:text-gray-100">{{ $title ?? 'Administration' }}</h1>
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
    </body>
</html>
