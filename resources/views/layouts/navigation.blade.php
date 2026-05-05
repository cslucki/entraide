<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('home') }}" class="flex items-center">
                        <img src="/favicon.svg" alt="BouclePro" class="h-8 w-8">
                    </a>
                    @isset($currentCommunity)
                    <span class="ml-2 text-xs text-gray-500 dark:text-gray-400 border-l border-gray-300 dark:border-gray-600 pl-2">{{ $currentCommunity->name }}</span>
                    @endisset
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')">
                        Propositions
                    </x-nav-link>
                    <x-nav-link :href="route('members.index')" :active="request()->routeIs('members*')">
                        Annuaire
                    </x-nav-link>
                    <x-nav-link :href="route('boucles.index')" :active="request()->routeIs('boucles*')">
                        Boucles
                    </x-nav-link>
                </div>
            </div>

            <!-- Recherche globale -->
            <div class="hidden sm:flex sm:items-center flex-1 max-w-xs mx-4">
                <form action="{{ route('search') }}" method="GET" class="relative w-full">
                    <input type="text" name="q" value="{{ request('q') }}"
                        placeholder="Rechercher..."
                        class="w-full pl-9 pr-4 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    <svg class="absolute left-3 top-2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </form>
            </div>

            <!-- Right side -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 space-x-3">
                @auth
                <!-- Points balance -->
                <a href="{{ route('points.index') }}" class="flex items-center gap-1 px-3 py-1 bg-indigo-50 dark:bg-indigo-900 rounded-full text-sm font-semibold text-indigo-700 dark:text-indigo-300">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16A8 8 0 0010 2zm1 11H9V9h2v4zm0-6H9V5h2v2z"/></svg>
                    {{ Auth::user()->points_balance }} pts
                </a>

                <!-- Messages icon avec badge -->
                @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                <a href="{{ route('messages.index') }}" class="relative p-2 text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition" title="Messages">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/>
                    </svg>
                    @if($unread > 0)
                    <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">
                        {{ $unread > 9 ? '9+' : $unread }}
                    </span>
                    @endif
                </a>

                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                            <img src="{{ Auth::user()->avatar_url }}" class="w-7 h-7 rounded-full" alt="">
                            <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">{{ Auth::user()->name }}</span>
                            <svg class="fill-current h-4 w-4 text-gray-400" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            <span class="font-medium">Dashboard</span>
                        </x-dropdown-link>
                        <x-dropdown-link :href="route('profile.show', Auth::user())">
                            Mon profil public
                        </x-dropdown-link>
                        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                        <x-dropdown-link :href="route('services.create')">
                            + Publier un {{ $T['service'] }}
                        </x-dropdown-link>
                        <x-dropdown-link :href="route('requests.create')">
                            + Publier une demande
                        </x-dropdown-link>
                        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                        <x-dropdown-link :href="route('points.index')">
                            Historique des points
                        </x-dropdown-link>
                        <x-dropdown-link :href="route('favorites.index')">
                            Mes favoris
                        </x-dropdown-link>
                        <x-dropdown-link :href="route('profile.edit')">
                            Paramètres
                        </x-dropdown-link>
                        @if(Auth::user()->is_admin)
                        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                        <x-dropdown-link :href="route('admin.dashboard')">
                            <span class="text-purple-600 dark:text-purple-400 font-medium">Administration</span>
                        </x-dropdown-link>
                        @endif
                        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                Déconnexion
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
                @else
                <a href="{{ route('login') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">Connexion</a>
                <a href="{{ route('register') }}" class="ml-2 px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">Inscription</a>
                @endauth
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                @auth
                @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                <a href="{{ route('messages.index') }}" class="relative p-2 mr-1 text-gray-500 hover:text-indigo-600 transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/>
                    </svg>
                    @if($unread > 0)
                    <span class="absolute top-0 right-0 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </a>
                @endauth
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')">
                Propositions
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('members.index')" :active="request()->routeIs('members*')">
                Annuaire
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('boucles.index')" :active="request()->routeIs('boucles*')">
                Boucles
            </x-responsive-nav-link>
        </div>

        @auth
        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
            <div class="px-4 flex items-center gap-3 mb-3">
                <img src="{{ Auth::user()->avatar_url }}" class="w-9 h-9 rounded-full" alt="">
                <div>
                    <div class="font-medium text-base text-gray-800 dark:text-gray-200">{{ Auth::user()->name }}</div>
                    <div class="font-medium text-sm text-gray-500">{{ Auth::user()->points_balance }} pts</div>
                </div>
            </div>
            <div class="mt-1 space-y-1">
                <x-responsive-nav-link :href="route('dashboard')">Dashboard</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('profile.show', Auth::user())">Mon profil public</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('services.create')">+ Publier un {{ $T['service'] }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('requests.create')">+ Publier une demande</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('points.index')">Historique des points</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('favorites.index')">Mes favoris</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('profile.edit')">Paramètres</x-responsive-nav-link>
                @if(Auth::user()->is_admin)
                <x-responsive-nav-link :href="route('admin.dashboard')">Administration</x-responsive-nav-link>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                        Déconnexion
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
        @endauth
    </div>
</nav>
