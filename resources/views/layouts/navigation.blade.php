<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo (desktop only) -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('home') }}" class="flex items-center">
                        <img src="/favicon.svg" alt="BouclePro" class="h-8 w-8 hidden sm:block">
                    </a>
                    @php $tenant = $currentOrganization ?? null; @endphp
                    @isset($tenant)
                    <span class="text-sm sm:text-xs text-gray-500 dark:text-gray-400 font-medium sm:border-l sm:border-gray-300 sm:dark:border-gray-600 sm:pl-2">{{ $tenant->name }}</span>
                    @endisset
                </div>

                <!-- Navigation Links (desktop) -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')">Échanges</x-nav-link>
                    <x-nav-link :href="route('members.index')" :active="request()->routeIs('members*')">Annuaire</x-nav-link>
                    <x-nav-link :href="route('blog.index')" :active="request()->routeIs('blog*')">Blog</x-nav-link>
                    @auth
                        <x-nav-link :href="route('loops.index')" :active="request()->routeIs('loops*')">Boucles</x-nav-link>
                    @else
                        <x-nav-link :href="route('boucles.index')" :active="request()->routeIs('boucles*')">Boucles</x-nav-link>
                    @endauth
                </div>
            </div>

            <!-- Recherche globale (desktop) -->
            <div class="hidden sm:flex sm:items-center flex-1 max-w-xs mx-4">
                <form action="{{ route('search') }}" method="GET" class="relative w-full">
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Rechercher..."
                        class="w-full pl-9 pr-4 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </form>
            </div>

            <!-- Right side (desktop) -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 space-x-3">
                <!-- Dark Mode Toggle -->
                <button @click="$store.darkMode.toggle()" class="p-2 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                    <template x-if="!$store.darkMode.on">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    </template>
                    <template x-if="$store.darkMode.on">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </template>
                </button>
                @auth
                <!-- Bouton Publier -->
                <div x-data="{ pubOpen: false }" class="relative" @click.outside="pubOpen = false">
                    <button @click="pubOpen = !pubOpen"
                            class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Publier
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                    <div x-show="pubOpen"
                         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                         class="absolute right-0 mt-2 w-52 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 py-1 z-50" style="display:none">
                        <a href="{{ route('requests.create') }}"
                           class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-4 h-4 text-orange-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Faire une {{ $T['request'] }}
                        </a>
                        <a href="{{ route('services.create') }}"
                           class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-4 h-4 text-indigo-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            Proposer un {{ $T['service'] }}
                        </a>
                        <a href="{{ route('blog.create') }}"
                           class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Écrire un article
                        </a>
                    </div>
                </div>

                <!-- Points balance -->
                <a href="{{ route('points.index') }}" class="flex items-center gap-1 px-3 py-1 bg-indigo-50 dark:bg-indigo-900 rounded-full text-sm font-semibold text-indigo-700 dark:text-indigo-300">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16A8 8 0 0010 2zm1 11H9V9h2v4zm0-6H9V5h2v2z"/></svg>
                    {{ Auth::user()->points_balance }} pts
                </a>

                <!-- Messages -->
                @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                <a href="{{ route('messages.index') }}" class="relative p-2 text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition" title="Messages">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/></svg>
                    @if($unread > 0)
                    <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </a>

                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                            <img src="{{ Auth::user()->avatar_url }}" class="w-7 h-7 rounded-full" alt="">
                            <span class="text-sm text-gray-700 dark:text-gray-300 font-medium">{{ Auth::user()->name }}</span>
                            <svg class="fill-current h-4 w-4 text-gray-400" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('dashboard')" :active="request()->routeIs('dashboard')"><span class="font-medium">Tableau de bord</span></x-dropdown-link>
                        <x-dropdown-link :href="route('profile.show', Auth::user())">Mon profil public</x-dropdown-link>
                        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                        <x-dropdown-link :href="route('services.create')">Proposer un {{ $T['service'] }}</x-dropdown-link>
                        <x-dropdown-link :href="route('requests.create')">Faire une {{ $T['request'] }}</x-dropdown-link>
                        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                        <x-dropdown-link :href="route('points.index')">Historique des points</x-dropdown-link>
                        <x-dropdown-link :href="route('points.index') . '#invitations'">Invitations</x-dropdown-link>
                        <x-dropdown-link :href="route('favorites.index')">Mes favoris</x-dropdown-link>
                        <x-dropdown-link :href="route('blog.my-posts')">Mes articles</x-dropdown-link>
                        <x-dropdown-link :href="route('profile.edit')">Profil et paramètres</x-dropdown-link>
                        @if(Auth::user()->is_admin)
                        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                        <x-dropdown-link :href="route('admin.dashboard')"><span class="text-purple-600 dark:text-purple-400 font-medium">Administration</span></x-dropdown-link>
                        @endif
                        <div class="border-t border-gray-100 dark:border-gray-600 my-1"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Déconnexion</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
                @else
                <a href="{{ route('login') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">Connexion</a>
                <a href="{{ route('register') }}" class="ml-2 px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">Inscription</a>
                @endauth
            </div>

            <!-- Mobile right : messages (auth) + Se connecter (guest) + hamburger -->
            <div class="flex items-center gap-2 sm:hidden">
                @auth
                @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                <a href="{{ route('messages.index') }}" class="relative p-2 text-gray-500 hover:text-indigo-600 transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/></svg>
                    @if($unread > 0)
                    <span class="absolute top-0 right-0 min-w-[14px] h-3.5 px-1 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </a>
                @else
                <a href="{{ route('login') }}"
                   class="px-3 py-1.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition">
                    Se connecter
                </a>
                <!-- Dark Mode Toggle Mobile -->
                <button @click="$store.darkMode.toggle()" class="p-2 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition">
                    <template x-if="!$store.darkMode.on">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    </template>
                    <template x-if="$store.darkMode.on">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </template>
                </button>
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
            <!-- Navigation Links Mobile -->
            <x-responsive-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')">Échanges</x-responsive-nav-link>
            <x-responsive-nav-link :href="route('members.index')" :active="request()->routeIs('members*')">Annuaire</x-responsive-nav-link>
            <x-responsive-nav-link :href="route('blog.index')" :active="request()->routeIs('blog*')">Blog</x-responsive-nav-link>
            @auth
                <x-responsive-nav-link :href="route('loops.index')" :active="request()->routeIs('loops*')">Boucles</x-responsive-nav-link>
            @else
                <x-responsive-nav-link :href="route('boucles.index')" :active="request()->routeIs('boucles*')">Boucles</x-responsive-nav-link>
            @endauth
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
                <x-responsive-nav-link :href="route('dashboard')">Tableau de bord</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('profile.show', Auth::user())">Mon profil public</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('services.create')">Proposer un {{ $T['service'] }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('requests.create')">Faire une {{ $T['request'] }}</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('points.index')">Historique des points</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('points.index') . '#invitations'">Invitations</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('favorites.index')">Mes favoris</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('blog.my-posts')">Mes articles</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('profile.edit')">Profil et paramètres</x-responsive-nav-link>
                @if(Auth::user()->is_admin)
                <x-responsive-nav-link :href="route('admin.dashboard')">Administration</x-responsive-nav-link>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Déconnexion</x-responsive-nav-link>
                </form>
            </div>
        </div>
        @else
        <div class="pt-4 pb-3 border-t border-gray-200 dark:border-gray-600 px-4 space-y-2">
            <a href="{{ route('login') }}" class="block text-center py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition">Se connecter</a>
            <a href="{{ route('register') }}" class="block text-center py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">Créer un compte</a>
        </div>
        @endauth
    </div>
</nav>
