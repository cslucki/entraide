{{-- TASK-1609 : `data-bp-mobile-nav` est un simple POINT D'ANCRAGE. Il
     n'ajoute aucun comportement et ne change rien au rendu ; il permet a une
     page qui remplace cette barre (le logigramme) de la masquer par un
     selecteur STABLE, au lieu de viser `nav.fixed.bottom-0` — une chaine de
     classes utilitaires qui casserait au premier ajustement de style.

     TASK-1625 : la barre DEFILE horizontalement.

     Elle etait `flex justify-around` avec des onglets `flex-1` : la largeur
     de l'ecran fixait donc le nombre d'entrees possibles, et Agenda, Dossiers
     et Blog — pourtant des destinations de premier niveau du rail desktop —
     n'avaient nulle part ou aller. Sur telephone, Dossiers et Agenda
     n'etaient atteignables par AUCUN chemin.

     La hauteur `h-16` est un CONTRAT, pas un choix de style : trois choses en
     dependent et se decaleraient ensemble si elle bougeait —
     `.mobile-safe-bottom-auth` (4rem, layouts/app.blade.php:181), le FAB « + »
     (`bottom-20`) et le FAB IA (`bottom-36`). Elle ne change pas. --}}
<nav data-bp-mobile-nav
     x-data="{
         centrerActif() {
             const actif = this.$refs.piste?.querySelector('[data-bp-nav-active=\'true\']');
             if (! actif) { return; }
             {{-- Au chargement, l'onglet actif peut etre hors du champ visible :
                  la barre s'ouvrirait alors sur une position ou le membre ne
                  voit pas ou il est. `block: 'nearest'` evite de faire defiler
                  la PAGE en meme temps. --}}
             actif.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'instant' });
         },
     }"
     x-init="$nextTick(() => centrerActif())"
     class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm border-t border-gray-200 dark:border-gray-700 pb-[env(safe-area-inset-bottom)] shadow-[0_-2px_8px_rgba(0,0,0,0.06)] dark:shadow-[0_-2px_8px_rgba(0,0,0,0.3)]">
    {{-- Le masque de bord dit, sans un mot et sans un bouton, que la bande
         continue. Il est purement decoratif : `mask-image` n'intercepte aucun
         clic et un navigateur qui l'ignore rend simplement une bande nette. --}}
    <div x-ref="piste"
         data-bp-mobile-nav-track
         class="flex items-center h-16 px-2 gap-1 overflow-x-auto overscroll-x-contain snap-x snap-proximity scroll-smooth [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [mask-image:linear-gradient(to_right,transparent,black_1.25rem,black_calc(100%-1.25rem),transparent)]">
        @php
            $currentRoute = request()->route()?->getName() ?? '';
            $currentOrganization = currentOrganization();
            $menuOrganization = (auth()->check() ? auth()->user()->organization : null) ?? $currentOrganization;
            $organizationRouteParam = request()->route('organization') ?: (auth()->check() ? $menuOrganization?->slug : null);
            $canSeeFlux = auth()->check() && auth()->user()->can('create', \App\Models\FeedPost::class);
            $loopsEnabled = $currentOrganization?->loops_enabled ?? $menuOrganization?->loops_enabled ?? true;
            $tabUrl = function (string $rootRoute, ?string $organizationRoute = null) use ($organizationRouteParam): string {
                if ($organizationRouteParam && $organizationRoute && Route::has($organizationRoute)) {
                    return route($organizationRoute, ['organization' => $organizationRouteParam]);
                }

                return route($rootRoute);
            };

            // TASK-1625 — l'icone de « Boucles ».
            //
            // Elle montrait une FEUILLE DE PAPIER (document-text), qui ne dit
            // ni le groupe, ni le cercle, ni la collaboration. Le rail desktop,
            // lui, montre une bulle de chat ronde — indiscernable de
            // « Messagerie » a 24 px.
            //
            // Trois noeuds relies en anneau ferme : le cercle et le collectif
            // se lisent d'un coup, et la forme ne ressemble a aucune autre de
            // la barre (ni bulle, ni barres, ni dossier). C'est l'idiome du
            // depot — `loops/card-icon.blade.php` dessine sa Roadmap
            // exactement ainsi, en points relies — et aucune bibliotheque
            // n'est ajoutee pour autant.
            $iconeBoucles = 'M12 3.75a1.75 1.75 0 1 1 0 3.5 1.75 1.75 0 0 1 0-3.5Z'
                .' M5.25 15.5a1.75 1.75 0 1 1 0 3.5 1.75 1.75 0 0 1 0-3.5Z'
                .' M18.75 15.5a1.75 1.75 0 1 1 0 3.5 1.75 1.75 0 0 1 0-3.5Z'
                .' M10.7 6.6 6.6 14.1 M13.3 6.6l4.1 7.5 M7.5 17.25h9';

            $tabs = auth()->check() ? [
                ['key' => 'loops', 'url' => $tabUrl('loops.index', 'organization.loops.index'), 'active' => 'loops', 'label' => __('navigation.loops'), 'icon' => $iconeBoucles, 'visible' => $loopsEnabled],
                ['key' => 'flux', 'url' => $organizationRouteParam && Route::has('organization.flux') ? route('organization.flux', ['organization' => $organizationRouteParam]) : route('dashboard'), 'active' => 'flux', 'label' => __('navigation.feed'), 'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h7l2 2h5a2 2 0 012 2v10a2 2 0 01-2 2z', 'visible' => $canSeeFlux],
                ['key' => 'exchanges', 'url' => $tabUrl('explorer', 'organization.explorer'), 'active' => 'explorer', 'label' => __('navigation.exchanges'), 'icon' => 'M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
                ['key' => 'messages', 'url' => $tabUrl('messages.index', 'organization.messages.index'), 'active' => 'messages', 'label' => __('navigation.messaging'), 'icon' => 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z'],
                // TASK-1625 — les trois destinations que la barre fixe ne
                // pouvait pas porter. Route, libelle, icone et garde de
                // visibilite sont repris TELS QUELS du rail desktop
                // (`app-side-nav.blade.php`) : aucune route n'est creee, aucune
                // architecture d'information n'est inventee. Ce sont les memes
                // destinations, enfin atteignables sur telephone.
                ['key' => 'agenda', 'url' => $tabUrl('events.agenda', 'organization.events.agenda'), 'active' => 'events.agenda', 'label' => __('navigation.agenda'), 'icon' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0V11.25A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5', 'visible' => $loopsEnabled],
                ['key' => 'members', 'url' => $tabUrl('members.index', 'organization.members.index'), 'active' => 'members', 'label' => __('navigation.directory'), 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                ['key' => 'dossiers', 'url' => $organizationRouteParam && Route::has('organization.dossiers.index') ? route('organization.dossiers.index', ['organization' => $organizationRouteParam]) : '#', 'active' => 'organization.dossiers', 'label' => __('navigation.my_dossiers'), 'icon' => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z', 'visible' => (bool) $organizationRouteParam && Route::has('organization.dossiers.index')],
                ['key' => 'blog', 'url' => $tabUrl('blog.index', 'organization.blog.index'), 'active' => 'blog', 'label' => __('navigation.blog'), 'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2M7 8h6M7 12h6M7 16h4'],
            ] : [
                ['key' => 'loops', 'url' => $tabUrl('boucles.index', 'organization.boucles.index'), 'active' => 'boucles', 'label' => __('navigation.loops'), 'icon' => $iconeBoucles],
                ['key' => 'exchanges', 'url' => $tabUrl('explorer', 'organization.explorer'), 'active' => 'explorer', 'label' => __('navigation.exchanges'), 'icon' => 'M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
                ['key' => 'members', 'url' => $tabUrl('members.index', 'organization.members.index'), 'active' => 'members', 'label' => __('navigation.directory'), 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                ['key' => 'blog', 'url' => $tabUrl('blog.index', 'organization.blog.index'), 'active' => 'blog', 'label' => __('navigation.blog'), 'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z'],
            ];
            $tabs = array_values(array_filter($tabs, fn (array $tab): bool => $tab['visible'] ?? true));
        @endphp
        @foreach($tabs as $tab)
        @php
            $isExcluded = false;

            foreach (($tab['active_exclude'] ?? []) as $excludedRoute) {
                if ($currentRoute === $excludedRoute || str_starts_with($currentRoute, $excludedRoute . '.')) {
                    $isExcluded = true;
                    break;
                }
            }

            if ($isExcluded) {
                $isActive = false;
            } else {
                $isActive = str_starts_with($currentRoute, $tab['active']) || str_starts_with($currentRoute, 'organization.' . $tab['active']);
            }
        @endphp
        {{-- `shrink-0` et une largeur MINIMALE remplacent `flex-1` : c'est ce
             couple qui fait defiler la bande au lieu de comprimer les onglets
             jusqu'a rendre les libelles illisibles. 4.5rem = 72 px, au-dessus
             des 44 px de cible tactile recommandes. --}}
        <a href="{{ $tab['url'] }}"
           data-bp-nav-item="{{ $tab['key'] }}"
           data-bp-nav-active="{{ $isActive ? 'true' : 'false' }}"
           @if($isActive) aria-current="page" @endif
           class="group flex shrink-0 snap-start flex-col items-center justify-center gap-1 min-w-[4.5rem] h-14 rounded-xl px-2 transition-colors duration-150 {{ $isActive ? 'bg-indigo-50 dark:bg-indigo-500/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/60' }}">
            <svg class="block w-6 h-6 shrink-0 {{ $isActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-400 dark:text-gray-500' }}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="{{ $tab['icon'] }}" />
            </svg>
            <span class="text-[10px] leading-none whitespace-nowrap {{ $isActive ? 'text-indigo-600 dark:text-indigo-400 font-semibold' : 'text-gray-500 dark:text-gray-400 font-medium' }}">{{ $tab['label'] }}</span>
            {{-- L'indicateur est TOUJOURS rendu, transparent quand l'onglet est
                 au repos. Ne le poser que sur l'actif ajoutait sa hauteur a un
                 seul onglet : icone et libelle sautaient de deux pixels a
                 chaque changement de page. --}}
            <span aria-hidden="true" class="h-0.5 w-5 rounded-full transition-colors duration-150 {{ $isActive ? 'bg-indigo-600 dark:bg-indigo-400' : 'bg-transparent' }}"></span>
        </a>
        @endforeach
    </div>
</nav>
