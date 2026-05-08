<nav x-data="{ open: false }" class="sticky top-0 z-50 bg-white/80 dark:bg-zinc-950/80 backdrop-blur-md border-b border-zinc-100 dark:border-zinc-900">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('home') }}" class="flex items-center group transition-transform hover:scale-105">
                        <img src="/favicon.svg" alt="BouclePro" class="h-7 w-7">
                        <span class="ml-2.5 text-lg font-bold text-zinc-900 dark:text-white tracking-tight">BouclePro</span>
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden md:flex items-center ml-10 space-x-1">
                    <x-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')" class="px-4 py-2 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-900 transition-colors border-none text-sm font-semibold">Échanges</x-nav-link>
                    <x-nav-link :href="route('members.index')" :active="request()->routeIs('members*')" class="px-4 py-2 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-900 transition-colors border-none text-sm font-semibold">Annuaire</x-nav-link>
                    <x-nav-link :href="route('blog.index')" :active="request()->routeIs('blog*')" class="px-4 py-2 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-900 transition-colors border-none text-sm font-semibold">Blog</x-nav-link>
                </div>
            </div>

            <!-- Right side -->
            <div class="hidden md:flex md:items-center md:ms-6 space-x-4">
                <!-- Dark Mode Toggle -->
                <button @click="$store.darkMode.toggle()" class="p-2 rounded-xl text-zinc-400 hover:text-zinc-900 dark:hover:text-white hover:bg-zinc-50 dark:hover:bg-zinc-900 transition-all">
                    <template x-if="!$store.darkMode.on">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    </template>
                    <template x-if="$store.darkMode.on">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </template>
                </button>

                @auth
                <!-- Points balance -->
                <a href="{{ route('points.index') }}" class="hidden lg:flex items-center gap-2 px-3 py-1.5 bg-zinc-50 dark:bg-zinc-900 rounded-xl text-[13px] font-bold text-zinc-700 dark:text-zinc-300 border border-zinc-100 dark:border-zinc-800">
                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                    {{ Auth::user()->points_balance }} pts
                </a>

                <!-- Notifications & Messages -->
                <div class="flex items-center gap-1">
                    <a href="{{ route('messages.index') }}" class="relative p-2 rounded-xl text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-zinc-50 dark:hover:bg-zinc-900 transition-all">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/></svg>
                        @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                        @if($unread > 0)
                        <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-red-500 rounded-full ring-2 ring-white dark:ring-zinc-950"></span>
                        @endif
                    </a>
                </div>

                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button class="flex items-center gap-2.5 pl-1 pr-3 py-1 rounded-2xl bg-zinc-50 dark:bg-zinc-900 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors border border-zinc-100 dark:border-zinc-800">
                            <img src="{{ Auth::user()->avatar_url }}" class="w-8 h-8 rounded-full border border-white dark:border-zinc-800" alt="">
                            <span class="text-sm text-zinc-700 dark:text-zinc-300 font-bold">{{ Auth::user()->name }}</span>
                            <svg class="h-4 w-4 text-zinc-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="px-4 py-2 border-b border-zinc-50 dark:border-zinc-800">
                            <p class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Compte</p>
                        </div>
                        <x-dropdown-link :href="route('dashboard')">Tableau de bord</x-dropdown-link>
                        <x-dropdown-link :href="route('profile.show', Auth::user())">Profil public</x-dropdown-link>
                        <div class="border-t border-zinc-50 dark:border-zinc-800 my-1"></div>
                        <x-dropdown-link :href="route('services.create')">Proposer un service</x-dropdown-link>
                        <x-dropdown-link :href="route('requests.create')">Faire une demande</x-dropdown-link>
                        @if(Auth::user()->is_admin)
                        <div class="border-t border-zinc-50 dark:border-zinc-800 my-1"></div>
                        <x-dropdown-link :href="route('admin.dashboard')"><span class="text-indigo-600 dark:text-indigo-400 font-bold">Admin Panel</span></x-dropdown-link>
                        @endif
                        <div class="border-t border-zinc-50 dark:border-zinc-800 my-1"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();" class="text-red-500">Déconnexion</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
                @else
                <div class="flex items-center gap-4">
                    <a href="{{ route('login') }}" class="text-sm font-bold text-zinc-500 hover:text-zinc-900 dark:hover:text-white transition-colors">Connexion</a>
                    <a href="{{ route('register') }}" class="px-5 py-2.5 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 text-sm font-bold rounded-xl hover:opacity-90 transition-all shadow-lg shadow-zinc-900/5">Inscription</a>
                </div>
                @endauth
            </div>

            <!-- Hamburger -->
            <div class="flex items-center md:hidden">
                <button @click="open = ! open" class="p-2 rounded-xl text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-900 transition-colors">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden md:hidden bg-white dark:bg-zinc-950 border-t border-zinc-100 dark:border-zinc-900">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('explorer')" :active="request()->routeIs('explorer*')">Échanges</x-responsive-nav-link>
            <x-responsive-nav-link :href="route('members.index')" :active="request()->routeIs('members*')">Annuaire</x-responsive-nav-link>
            <x-responsive-nav-link :href="route('blog.index')" :active="request()->routeIs('blog*')">Blog</x-responsive-nav-link>
        </div>

        @auth
        <div class="pt-4 pb-1 border-t border-zinc-100 dark:border-zinc-900">
            <div class="px-6 flex items-center gap-4">
                <img src="{{ Auth::user()->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
                <div>
                    <div class="font-bold text-zinc-900 dark:text-white">{{ Auth::user()->name }}</div>
                    <div class="text-sm font-medium text-zinc-500">{{ Auth::user()->points_balance }} points</div>
                </div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('dashboard')">Tableau de bord</x-responsive-nav-link>
                <x-responsive-nav-link :href="route('profile.edit')">Paramètres</x-responsive-nav-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();" class="text-red-500">Déconnexion</x-responsive-nav-link>
                </form>
            </div>
        </div>
        @else
        <div class="py-4 px-6 space-y-3">
            <a href="{{ route('login') }}" class="block w-full text-center py-3 border border-zinc-200 dark:border-zinc-800 text-zinc-600 dark:text-zinc-300 rounded-xl font-bold">Connexion</a>
            <a href="{{ route('register') }}" class="block w-full text-center py-3 bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-xl font-bold">Inscription</a>
        </div>
        @endauth
    </div>
</nav>
