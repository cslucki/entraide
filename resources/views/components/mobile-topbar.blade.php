@php
    $routeName = request()->route()?->getName() ?? '';
    $organizationRouteParam = request()->route('organization');

    $routeUrl = function (string $rootRoute, ?string $organizationRoute = null) use ($organizationRouteParam): string {
        if ($organizationRouteParam && $organizationRoute && Route::has($organizationRoute)) {
            return route($organizationRoute, ['organization' => $organizationRouteParam]);
        }

        return route($rootRoute);
    };

    $levelOneTitles = [
        'home' => 'Accueil',
        'organization.home' => 'Accueil',
        'explorer' => 'Échanges',
        'organization.explorer' => 'Échanges',
        'boucles.index' => 'Boucles',
        'loops.index' => 'Boucles',
        'organization.loops.index' => 'Boucles',
        'blog.index' => 'Actus',
        'mentions-legales' => 'Mentions légales',
        'dashboard' => 'Mon espace',
        'organization.dashboard' => 'Mon espace',
        'login' => 'Connexion',
        'organization.login' => 'Connexion',
    ];

    $isLevelOne = array_key_exists($routeName, $levelOneTitles);
    $routeTitle = null;

    if (! $isLevelOne) {
        if (request()->routeIs('services.show', 'organization.services.show')) {
            $routeTitle = request()->route('service')?->title;
        } elseif (request()->routeIs('services.create', 'organization.services.create')) {
            $routeTitle = 'Proposer un service';
        } elseif (request()->routeIs('services.edit', 'organization.services.edit')) {
            $routeTitle = 'Modifier le service';
        } elseif (request()->routeIs('requests.show', 'organization.requests.show')) {
            $routeTitle = request()->route('request')?->title;
        } elseif (request()->routeIs('requests.create', 'organization.requests.create')) {
            $routeTitle = 'Faire une demande';
        } elseif (request()->routeIs('blog.show')) {
            $routeTitle = request()->route('post')?->title;
        } elseif (request()->routeIs('blog.create')) {
            $routeTitle = 'Écrire un article';
        } elseif (request()->routeIs('blog.edit')) {
            $routeTitle = 'Modifier l’article';
        } elseif (request()->routeIs('blog.my-posts')) {
            $routeTitle = 'Mes articles';
        } elseif (request()->routeIs('profile.show', 'organization.profile.show')) {
            $routeTitle = request()->route('user')?->name;
        } elseif (request()->routeIs('profile.edit', 'organization.profile.edit')) {
            $routeTitle = 'Profil';
        } elseif (request()->routeIs('loops.show', 'organization.loops.show')) {
            $routeTitle = request()->route('loop')?->name;
        } elseif (request()->routeIs('loops.create', 'organization.loops.create')) {
            $routeTitle = 'Créer une boucle';
        } elseif (request()->routeIs('messages.*', 'organization.messages.*')) {
            $routeTitle = 'Messages';
        } elseif (request()->routeIs('points.*', 'organization.points.*')) {
            $routeTitle = 'Points';
        } elseif (request()->routeIs('favorites.*', 'organization.favorites.*')) {
            $routeTitle = 'Favoris';
        } elseif (request()->routeIs('bug-reports.*', 'organization.bug-reports.*')) {
            $routeTitle = 'Bugs signalés';
        }
    }

    $displayTitle = $levelOneTitles[$routeName] ?? ($routeTitle ?: ($title ?? config('app.name')));
    $backHref = null;

    if (! $isLevelOne) {
        if (request()->routeIs('services.*', 'requests.*', 'organization.services.*', 'organization.requests.*')) {
            $backHref = $routeUrl('explorer', 'organization.explorer');
        } elseif (request()->routeIs('blog.*')) {
            $backHref = route('blog.index');
        } elseif (request()->routeIs('loops.*', 'organization.loops.*')) {
            $_org = app()->bound('current_organization') ? app('current_organization') : null;
            if ($_org && $_org->isMonoLoop()) {
                $backHref = auth()->check() ? $routeUrl('home', 'organization.home') : route('home');
            } else {
                $backHref = auth()->check() && Route::has('loops.index') ? route('loops.index') : route('boucles.index');
            }
        } elseif (request()->routeIs('messages.*', 'points.*', 'favorites.*', 'profile.*', 'organization.messages.*', 'organization.points.*', 'organization.favorites.*', 'organization.profile.*')) {
            $backHref = auth()->check() ? $routeUrl('dashboard', 'organization.dashboard') : route('home');
        } elseif (request()->routeIs('bug-reports.*', 'organization.bug-reports.*')) {
            $backHref = $routeUrl('home', 'organization.home');
        }
    }
@endphp

<header class="md:hidden fixed top-0 inset-x-0 z-40 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 pt-[env(safe-area-inset-top)]">
    <div class="flex items-center justify-between h-14 px-4 gap-3">
        <div class="flex min-w-0 items-center gap-3">
            @if(request()->routeIs('login', 'organization.login'))
            <a href="{{ url('/') }}" class="flex items-center gap-2 min-w-0" aria-label="Accueil {{ $brandOrganizationName ?? config('app.name') }}">
                <img src="/brand/bouclepro-symbol-64.png" alt="" class="h-9 w-9 shrink-0">
                <span class="truncate text-base font-bold text-gray-900 dark:text-gray-100">{{ $brandOrganizationName ?? config('app.name') }}</span>
            </a>
            @elseif($backHref)
            <a href="{{ $backHref }}" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-950 dark:text-indigo-300 dark:hover:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900" aria-label="Retour">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            @endif
            @unless(request()->routeIs('login', 'organization.login'))
            <h1 class="truncate text-lg font-semibold text-gray-900 dark:text-gray-100 tracking-tight">{{ $displayTitle }}</h1>
            @endunless
        </div>
        <div class="flex items-center gap-2.5">
            @auth
            <a href="{{ route('messages.index') }}" class="relative w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center" aria-label="Messages">
                <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                @if($unread > 0)
                <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">{{ min($unread, 9) }}</span>
                @endif
            </a>

            <x-dropdown align="right" width="w-72" contentClasses="py-2 bg-white dark:bg-gray-800">
                <x-slot name="trigger">
                    <button class="flex items-center gap-1 rounded-full focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900" aria-label="Ouvrir le menu utilisateur">
                        <img src="{{ auth()->user()->avatar_url }}" class="w-9 h-9 rounded-full border-2 border-indigo-300 dark:border-indigo-700 object-cover" alt="{{ auth()->user()->name }}">
                        <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                        <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">{{ auth()->user()->name }}</div>
                        <a href="{{ route('points.index') }}" class="mt-1 inline-flex text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ auth()->user()->points_balance }} pts</a>
                    </div>

                    <x-dropdown-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Tableau de bord</x-dropdown-link>
                    <x-dropdown-link :href="route('profile.show', auth()->user())">Mon profil public</x-dropdown-link>
                    <x-dropdown-link :href="route('agent-ia.wizard')">Mon profil IA</x-dropdown-link>
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <x-dropdown-link :href="route('services.create')">Proposer un {{ $T['service'] ?? 'service' }}</x-dropdown-link>
                    <x-dropdown-link :href="route('requests.create')">Faire une {{ $T['request'] ?? 'demande' }}</x-dropdown-link>
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <x-dropdown-link :href="route('points.index')">Historique des points</x-dropdown-link>
                    <x-dropdown-link :href="route('points.index') . '#invitations'">Invitations</x-dropdown-link>
                    <x-dropdown-link :href="route('favorites.index')">Mes favoris</x-dropdown-link>
                    <x-dropdown-link :href="route('blog.my-posts')">Mes articles</x-dropdown-link>
                    <x-dropdown-link :href="route('profile.edit')">Profil et paramètres</x-dropdown-link>
                    @if(auth()->user()->is_admin)
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <x-dropdown-link :href="route('admin.dashboard')"><span class="text-purple-600 dark:text-purple-400 font-medium">Administration</span></x-dropdown-link>
                    @endif
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Déconnexion</x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
            @else
            <button @click="$store.darkMode.toggle()" class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-600 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900" aria-label="Changer de mode d'affichage">
                <svg class="block w-5 h-5 dark:hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                <svg class="hidden w-5 h-5 dark:block" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </button>
            @unless(request()->routeIs('login', 'organization.login'))
            <a href="{{ $routeUrl('login', 'organization.login') }}" class="inline-flex items-center rounded-full bg-indigo-600 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900">
                Connexion
            </a>
            @endunless
            @endauth
        </div>
    </div>
</header>
