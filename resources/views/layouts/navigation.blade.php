@php
    $T = [
        'service' => 'micro-service',
        'services' => 'micro-services',
        'request' => 'demande d’aide',
        'requests' => 'demandes d’aide',
        'Services' => 'Micro-services',
    ];
    $isHome = request()->routeIs('home');
@endphp
<nav x-data="{ open: false, scrolled: false }"
     @scroll.window="scrolled = (window.pageYOffset > 10)"
     :class="{ 'bg-white/80 dark:bg-zinc-900/80 backdrop-blur-lg border-b border-gray-200 dark:border-zinc-800': scrolled, 'bg-transparent': !scrolled && {{ $isHome ? 'true' : 'false' }} }"
     class="fixed w-full z-50 transition-all duration-300">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('home') }}" class="flex items-center gap-2 group">
                        <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <span class="font-black text-xl tracking-tight"
                              :class="{ 'text-gray-900 dark:text-white': scrolled || !{{ $isHome ? 'true' : 'false' }}, 'text-white': !scrolled && {{ $isHome ? 'true' : 'false' }} }">
                            Boucle<span class="text-indigo-500">Pro</span>
                        </span>
                    </a>
                </div>

                <!-- Navigation Links (Desktop) -->
                <div class="hidden sm:-my-px sm:ml-10 sm:flex sm:space-x-8">
                    <x-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')"
                                x-bind:class="!scrolled && {{ $isHome ? 'true' : 'false' }} ? 'text-indigo-100 hover:text-white border-transparent' : ''">
                        Échanges
                    </x-nav-link>
                    <x-nav-link :href="route('members.index')" :active="request()->routeIs('members*')"
                                x-bind:class="!scrolled && {{ $isHome ? 'true' : 'false' }} ? 'text-indigo-100 hover:text-white border-transparent' : ''">
                        Annuaire
                    </x-nav-link>
                    <x-nav-link :href="route('boucles.index')" :active="request()->routeIs('boucles*')"
                                x-bind:class="!scrolled && {{ $isHome ? 'true' : 'false' }} ? 'text-indigo-100 hover:text-white border-transparent' : ''">
                        Boucles
                    </x-nav-link>
                </div>
            </div>

            <!-- Settings Dropdown (Desktop) -->
            <div class="hidden sm:flex sm:items-center sm:ml-6 gap-4">
                @auth
                <!-- Messages -->
                @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                <a href="{{ route('messages.index') }}" class="relative p-2 text-gray-500 dark:text-zinc-400 hover:text-indigo-600 transition"
                   :class="{ 'text-indigo-200 hover:text-white': !scrolled && {{ $isHome ? 'true' : 'false' }} }">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/></svg>
                    @if($unread > 0)
                        <span class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                    @endif
                </a>

                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-xl hover:bg-gray-100 dark:hover:bg-zinc-800 transition"
                                :class="{ 'hover:bg-white/10': !scrolled && {{ $isHome ? 'true' : 'false' }} }">
                            <img src="{{ Auth::user()->avatar_url }}" class="w-8 h-8 rounded-full border-2 border-transparent group-hover:border-indigo-500 transition" alt="">
                            <span class="text-sm font-bold"
                                  :class="{ 'text-gray-700 dark:text-zinc-200': scrolled || !{{ $isHome ? 'true' : 'false' }}, 'text-white': !scrolled && {{ $isHome ? 'true' : 'false' }} }">{{ Auth::user()->name }}</span>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="px-4 py-2 border-b border-gray-100 dark:border-zinc-700 mb-1">
                            <p class="text-xs text-gray-500 uppercase font-black tracking-widest">Compte</p>
                            <p class="text-sm font-bold text-indigo-600 dark:text-indigo-400">{{ Auth::user()->points_balance }} points</p>
                        </div>
                        <x-dropdown-link :href="route('dashboard')">Tableau de bord</x-dropdown-link>
                        <x-dropdown-link :href="route('profile.show', Auth::user())">Profil public</x-dropdown-link>
                        <div class="border-t border-gray-100 dark:border-zinc-700 my-1"></div>
                        <x-dropdown-link :href="route('services.create')">Proposer un service</x-dropdown-link>
                        <x-dropdown-link :href="route('requests.create')">Demander de l'aide</x-dropdown-link>
                        <div class="border-t border-gray-100 dark:border-zinc-700 my-1"></div>
                        <x-dropdown-link :href="route('profile.edit')">Paramètres</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();" class="text-red-600">Déconnexion</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
                @else
                <a href="{{ route('login') }}" class="text-sm font-bold transition"
                   :class="{ 'text-gray-600 dark:text-zinc-400 hover:text-gray-900 dark:hover:text-white': scrolled || !{{ $isHome ? 'true' : 'false' }}, 'text-white/80 hover:text-white': !scrolled && {{ $isHome ? 'true' : 'false' }} }">Connexion</a>
                <a href="{{ route('register') }}" class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-indigo-500/20 hover:bg-indigo-700 transition transform hover:-translate-y-0.5">Inscription</a>
                @endauth
            </div>

            <!-- Mobile Hamburger -->
            <div class="flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-xl transition"
                        :class="{ 'text-gray-500 dark:text-zinc-400 hover:bg-gray-100 dark:hover:bg-zinc-800': scrolled || !{{ $isHome ? 'true' : 'false' }}, 'text-white hover:bg-white/10': !scrolled && {{ $isHome ? 'true' : 'false' }} }">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Sidebar (Drawer) -->
    <div x-show="open"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="-translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="-translate-x-full"
         class="fixed inset-0 z-50 flex sm:hidden"
         @click.away="open = false"
         x-cloak>
        <div class="relative flex-1 flex flex-col max-w-xs w-full bg-white dark:bg-zinc-900 shadow-2xl">
            <div class="absolute top-0 right-0 -mr-12 pt-4">
                <button @click="open = false" class="ml-1 flex items-center justify-center h-10 w-10 rounded-full focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white">
                    <span class="sr-only">Close sidebar</span>
                    <svg class="h-6 w-6 text-white" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="flex-1 h-0 pt-5 pb-4 overflow-y-auto">
                <div class="flex-shrink-0 flex items-center px-4 mb-8">
                    <span class="font-black text-2xl tracking-tight text-gray-900 dark:text-white">
                        Boucle<span class="text-indigo-500">Pro</span>
                    </span>
                </div>

                <nav class="px-2 space-y-1">
                    <x-responsive-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')">Échanges</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('members.index')" :active="request()->routeIs('members*')">Annuaire</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('boucles.index')" :active="request()->routeIs('boucles*')">Boucles</x-responsive-nav-link>
                </nav>

                @auth
                <div class="mt-8 pt-4 border-t border-gray-100 dark:border-zinc-800">
                    <div class="px-4 flex items-center gap-4 mb-4">
                        <img src="{{ Auth::user()->avatar_url }}" class="w-10 h-10 rounded-full border-2 border-indigo-500" alt="">
                        <div>
                            <div class="font-bold text-gray-800 dark:text-zinc-100">{{ Auth::user()->name }}</div>
                            <div class="text-sm font-bold text-indigo-600 dark:text-indigo-400">{{ Auth::user()->points_balance }} pts</div>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <x-responsive-nav-link :href="route('dashboard')">Tableau de bord</x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('services.create')">Proposer un service</x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('requests.create')">Demander de l'aide</x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('profile.edit')">Paramètres</x-responsive-nav-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();" class="text-red-600">Déconnexion</x-responsive-nav-link>
                        </form>
                    </div>
                </div>
                @else
                <div class="mt-8 pt-4 border-t border-gray-100 dark:border-zinc-800 px-4 space-y-3">
                    <a href="{{ route('register') }}" class="block w-full text-center py-4 bg-indigo-600 text-white rounded-2xl font-bold shadow-lg shadow-indigo-500/20">Créer un compte</a>
                    <a href="{{ route('login') }}" class="block w-full text-center py-4 border border-gray-200 dark:border-zinc-700 text-gray-700 dark:text-zinc-300 rounded-2xl font-bold">Se connecter</a>
                </div>
                @endauth
            </div>
        </div>
        <div class="flex-shrink-0 w-14" aria-hidden="true">
            <!-- Dummy element to force sidebar to shrink to fit close icon -->
        </div>
    </div>
</nav>
