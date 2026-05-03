<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('home') }}" class="text-xl font-bold text-indigo-600 dark:text-indigo-400">
                        Entraide
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')">
                        Explorateur
                    </x-nav-link>
                    @auth
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        Dashboard
                    </x-nav-link>
                    <x-nav-link :href="route('messages.index')" :active="request()->routeIs('messages*')">
                        <span class="relative inline-flex items-center">
                            Messages
                            @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                            @if($unread > 0)
                            <span class="absolute -top-2 -right-4 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">
                                {{ $unread > 9 ? '9+' : $unread }}
                            </span>
                            @endif
                        </span>
                    </x-nav-link>
                    @endauth
                </div>
            </div>

            <!-- Right side -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 space-x-4">
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
                <!-- Points balance -->
                <a href="{{ route('points.index') }}" class="flex items-center gap-1 px-3 py-1 bg-indigo-50 dark:bg-indigo-900 rounded-full text-sm font-semibold text-indigo-700 dark:text-indigo-300">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16A8 8 0 0010 2zm1 11H9V9h2v4zm0-6H9V5h2v2z"/></svg>
                    {{ Auth::user()->points_balance }} pts
                </a>

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>
                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.show', Auth::user())">
                            Mon profil public
                        </x-dropdown-link>
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
                <a href="{{ route('register') }}" class="ml-4 px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">Inscription</a>
                @endauth
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
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
            <div class="flex items-center px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400 mr-4">Apparence</span>
                <button @click="$store.darkMode.toggle()" class="flex items-center gap-2 p-2 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                    <template x-if="!$store.darkMode.on">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                            <span class="text-xs">Sombre</span>
                        </div>
                    </template>
                    <template x-if="$store.darkMode.on">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            <span class="text-xs">Clair</span>
                        </div>
                    </template>
                </button>
            </div>
            <x-responsive-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')">
                Explorateur
            </x-responsive-nav-link>
            @auth
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                Dashboard
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('messages.index')" :active="request()->routeIs('messages*')">
                Messages
            </x-responsive-nav-link>
            @endauth
        </div>

        @auth
        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 dark:text-gray-200">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->points_balance }} pts</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.show', Auth::user())">Mon profil</x-responsive-nav-link>
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
