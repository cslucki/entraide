@php
    $currentRoute = request()->route()?->getName() ?? '';
    $organizationRouteParam = request()->route('organization');

    $routeUrl = function (string $rootRoute, ?string $organizationRoute = null) use ($organizationRouteParam): string {
        if ($organizationRouteParam && $organizationRoute && Route::has($organizationRoute)) {
            return route($organizationRoute, ['organization' => $organizationRouteParam]);
        }

        return route($rootRoute);
    };

    $items = auth()->check() ? [
        [
            'url' => $routeUrl('loops.index', 'organization.loops.index'),
            'active' => ['loops', 'organization.loops'],
            'label' => 'Boucles',
            'hint' => 'ChatLoop',
            'icon' => 'M8 10h8M8 14h5m8-2a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'url' => $routeUrl('explorer', 'organization.explorer'),
            'active' => ['explorer', 'organization.explorer', 'messages'],
            'label' => 'Échanges',
            'hint' => 'Services',
            'icon' => 'M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4',
        ],
        [
            'url' => $routeUrl('members.index', 'organization.members.index'),
            'active' => ['members', 'organization.members', 'profile.show'],
            'label' => 'Annuaire',
            'hint' => 'Membres',
            'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm6 0V9a2 2 0 00-2-2h-2a2 2 0 00-2 2v10m6 0h2a2 2 0 002-2V5a2 2 0 00-2-2h-2a2 2 0 00-2 2v14z',
        ],
        [
            'url' => route('blog.index'),
            'active' => ['blog'],
            'label' => 'Actus',
            'hint' => 'Articles',
            'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 00-2-2h-2M7 8h6M7 12h6M7 16h4',
        ],
    ] : [
        [
            'url' => route('boucles.index'),
            'active' => ['boucles'],
            'label' => 'Boucles',
            'hint' => 'Groupes',
            'icon' => 'M8 10h8M8 14h5m8-2a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'url' => route('explorer'),
            'active' => ['explorer'],
            'label' => 'Échanges',
            'hint' => 'Services',
            'icon' => 'M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4',
        ],
        [
            'url' => route('login'),
            'active' => ['login', 'register'],
            'label' => 'Annuaire',
            'hint' => 'Connexion',
            'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm6 0V9a2 2 0 00-2-2h-2a2 2 0 00-2 2v10m6 0h2a2 2 0 002-2V5a2 2 0 00-2-2h-2a2 2 0 00-2 2v14z',
        ],
        [
            'url' => route('blog.index'),
            'active' => ['blog'],
            'label' => 'Actus',
            'hint' => 'Articles',
            'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 00-2-2h-2M7 8h6M7 12h6M7 16h4',
        ],
    ];

    $isActive = function (array $patterns) use ($currentRoute): bool {
        foreach ($patterns as $pattern) {
            if ($currentRoute === $pattern || str_starts_with($currentRoute, $pattern.'.')) {
                return true;
            }
        }

        return false;
    };

    $themes = config('bouclepro_themes.themes', []);
@endphp

<aside x-data class="hidden md:flex fixed inset-y-0 left-0 z-40 w-20 flex-col items-center border-r border-[var(--bp-border)] bg-[var(--bp-surface)]/95 text-[var(--bp-muted)] shadow-[8px_0_24px_rgba(15,23,42,0.05)] backdrop-blur">
    <div class="flex h-full w-full flex-col items-center py-4">
        <a href="{{ route('home') }}" class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--bp-panel)] shadow-sm ring-1 ring-[var(--bp-border)] transition hover:scale-105" aria-label="BouclePro">
            <img src="/brand/bouclepro-symbol-64.png" alt="" class="h-8 w-8" aria-hidden="true">
        </a>

        <button type="button" @click="$store.visualTheme.next()" class="mt-3 flex w-14 flex-col items-center rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-1.5 py-2 text-[10px] font-semibold uppercase tracking-wide text-[var(--bp-muted)] shadow-sm transition hover:text-[var(--bp-text)]" aria-label="Changer de thème">
            <span class="h-3 w-3 rounded-full bg-[var(--bp-primary)] ring-2 ring-[var(--bp-surface-soft)]" aria-hidden="true"></span>
            <span class="mt-1 leading-none" x-text="$store.visualTheme.label()">Sable</span>
        </button>

        <nav class="mt-6 flex w-full flex-1 flex-col items-center gap-2" aria-label="Navigation principale">
            @foreach($items as $item)
                @php $active = $isActive($item['active']); @endphp
                <a href="{{ $item['url'] }}"
                   class="group relative flex w-full flex-col items-center gap-1 px-2 py-2 text-[11px] font-medium transition {{ $active ? 'text-[var(--bp-primary)]' : 'text-[var(--bp-muted)] hover:text-[var(--bp-text)]' }}"
                   title="{{ $item['label'] }}">
                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl transition {{ $active ? 'bg-[color-mix(in_srgb,var(--bp-primary)_14%,transparent)] text-[var(--bp-primary)] shadow-sm' : 'bg-transparent group-hover:bg-[var(--bp-panel)] group-hover:shadow-sm' }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="{{ $item['icon'] }}" />
                        </svg>
                    </span>
                    <span class="leading-none">{{ $item['label'] }}</span>
                    @if($active)
                        <span class="absolute right-0 top-1/2 h-8 w-1 -translate-y-1/2 rounded-l-full bg-[var(--bp-primary)]"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="flex flex-col items-center gap-3 border-t border-[var(--bp-border)] pt-4">
            <button type="button" @click="$store.darkMode.toggle()" class="flex h-10 w-10 items-center justify-center rounded-2xl text-[var(--bp-muted)] transition hover:bg-[var(--bp-panel)] hover:text-[var(--bp-text)]" aria-label="Mode sombre">
                <template x-if="!$store.darkMode.on">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 1012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </template>
                <template x-if="$store.darkMode.on">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </template>
            </button>

            @auth
                <x-dropdown align="left-up" width="w-64" contentClasses="py-2 bg-white dark:bg-gray-800">
                    <x-slot name="trigger">
                        <button class="relative flex h-11 w-11 items-center justify-center rounded-full ring-2 ring-white transition hover:scale-105 dark:ring-gray-800" aria-label="Menu utilisateur">
                            <img src="{{ Auth::user()->avatar_url }}" class="h-10 w-10 rounded-full object-cover" alt="{{ Auth::user()->name }}">
                            <span class="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-[var(--bp-surface)] bg-[var(--bp-progress)]"></span>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="flex items-center gap-3 px-4 pb-3 pt-2">
                            <img src="{{ Auth::user()->avatar_url }}" class="h-10 w-10 rounded-full object-cover" alt="{{ Auth::user()->name }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ Auth::user()->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ Auth::user()->points_balance }} pts</p>
                            </div>
                        </div>

                        <div x-data="{ themeMenuOpen: false }" class="border-t border-gray-100 py-2 dark:border-gray-700">
                            <button type="button" @click="themeMenuOpen = !themeMenuOpen" class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700" :aria-expanded="themeMenuOpen.toString()">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 100 18 9 9 0 000-18z"/><path d="M12 3v18M12 12h9"/></svg>
                                <span>Changer de thème</span>
                                <span class="ml-auto text-xs text-gray-400" x-text="$store.visualTheme.label()">Sable</span>
                                <svg class="h-4 w-4 text-gray-400 transition" :class="themeMenuOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="themeMenuOpen" x-transition class="mx-3 mb-2 rounded-2xl bg-gray-50 p-1.5 dark:bg-gray-900/60">
                                @foreach($themes as $themeKey => $theme)
                                    <button type="button" @click="$store.visualTheme.set('{{ $themeKey }}')" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-xs text-gray-700 transition hover:bg-white dark:text-gray-200 dark:hover:bg-gray-800">
                                        <span class="h-3 w-3 rounded-full ring-2 ring-white dark:ring-gray-700" style="background-color: {{ $theme['tokens']['primary'] ?? '#0B4DFF' }}" aria-hidden="true"></span>
                                        <span class="font-medium">{{ $theme['label'] ?? ucfirst($themeKey) }}</span>
                                        <span x-show="$store.visualTheme.is('{{ $themeKey }}')" class="ml-auto text-[10px] font-semibold uppercase tracking-wide text-[var(--bp-primary)]">Actif</span>
                                    </button>
                                @endforeach
                            </div>
                            <button type="button" @click="$store.darkMode.toggle()" class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 1012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                                <span x-text="$store.darkMode.on ? 'Mode clair' : 'Mode sombre'">Mode sombre</span>
                            </button>
                        </div>

                        <div class="border-t border-gray-100 py-2 dark:border-gray-700">
                            <a href="{{ route('dashboard') }}" @click="open = false" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1"/></svg>
                                <span>Tableau de bord</span>
                            </a>
                            <a href="{{ route('profile.show', Auth::user()) }}" @click="open = false" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.75 7.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path d="M4.5 20.25a8.25 8.25 0 1116.5 0"/></svg>
                                <span>Profil</span>
                            </a>
                            <a href="{{ route('profile.edit') }}" @click="open = false" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.573c1.757.426 1.757 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.065c-.426 1.757-2.924 1.757-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.573c-1.757-.426-1.757-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.573-1.065z"/><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <span>Paramètres</span>
                            </a>
                            <a href="{{ route('points.index') }}" @click="open = false" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6v6l4 2"/><path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Historique des points</span>
                            </a>
                            <a href="{{ route('favorites.index') }}" @click="open = false" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111 5.52.442a.563.563 0 01.32.988l-4.205 3.602 1.285 5.385a.562.562 0 01-.84.61L12 16.75l-4.725 2.887a.562.562 0 01-.84-.61l1.285-5.385-4.205-3.602a.563.563 0 01.32-.988l5.52-.442 2.125-5.111z"/></svg>
                                <span>Mes favoris</span>
                            </a>
                        </div>

                        <div class="border-t border-gray-100 py-2 dark:border-gray-700">
                            <a href="{{ route('help') }}" @click="open = false" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.178-.43.326-.67.442-.745.361-1.451.999-1.451 1.827v.75"/><path d="M12 18h.01"/><path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Aide</span>
                            </a>
                        @if(Auth::user()->is_admin)
                            <a href="{{ route('admin.dashboard') }}" @click="open = false" class="flex items-center gap-3 px-4 py-2 text-sm font-medium text-purple-700 transition hover:bg-purple-50 dark:text-purple-300 dark:hover:bg-purple-900/30">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m0 0v10m6-14v2m0 0a2 2 0 100 4m0-4a2 2 0 110 4m0 0v8M6 6v8m0 0a2 2 0 100 4m0-4a2 2 0 110 4m0 0v2"/></svg>
                                <span>Administration</span>
                            </a>
                        @endif
                        </div>

                        <div class="border-t border-gray-100 pt-2 dark:border-gray-700">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" @click="open = false" class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                                    <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>
                                    <span>Se déconnecter</span>
                                </button>
                            </form>
                            <a href="{{ route('mentions-legales') }}" @click="open = false" class="mt-1 flex items-center gap-3 px-4 py-2 text-xs text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 12h6m-6 4h6"/><path d="M5 3h10l4 4v17H5z"/><path d="M15 3v4h4"/></svg>
                                <span>Mentions légales</span>
                            </a>
                        </div>
                    </x-slot>
                </x-dropdown>
            @else
                <a href="{{ route('login') }}" class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--bp-primary)] text-white shadow-sm transition hover:bg-[var(--bp-primary-deep)]" aria-label="Se connecter">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
                </a>
            @endauth
        </div>
    </div>
</aside>
