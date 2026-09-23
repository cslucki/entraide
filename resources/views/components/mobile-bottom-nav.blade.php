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
         indice: false,
         minuteur: null,
         resteADroite() {
             const piste = this.$refs.piste;
             return piste ? (piste.scrollWidth - piste.clientWidth - piste.scrollLeft) > 4 : false;
         },
         montrerIndice() {
             {{-- Au bout de la bande il n'y a plus rien a montrer : une fleche
                  qui pointe vers du vide est un mensonge poli. --}}
             if (! this.resteADroite()) { this.indice = false; return; }
             this.indice = true;
             clearTimeout(this.minuteur);
             this.minuteur = setTimeout(() => { this.indice = false; }, 1400);
         },
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
         @pointerdown="montrerIndice()"
         @scroll.passive="montrerIndice()"
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

            // TASK-1626 — les icones viennent desormais de `config/navigation_icons.php`.
            //
            // La rosette du logo, posee ici par TASK-1625, est ABANDONNEE
            // volontairement : le rail desktop rend en 16 px, et la mesure y a
            // montre qu'elle s'effondre en tache — aucune simplification
            // testee (6 cercles, 5 cercles, anneau a noeuds) n'a retrouve la
            // nettete de la bulle. L'harmonisation prime, et elle se fait sur
            // le trace qui tient aux DEUX tailles.

            $tabs = auth()->check() ? [
                ['key' => 'loops', 'url' => $tabUrl('loops.index', 'organization.loops.index'), 'active' => 'loops', 'label' => __('navigation.loops'), 'icon' => config('navigation_icons.loops'), 'visible' => $loopsEnabled],
                ['key' => 'flux', 'url' => $organizationRouteParam && Route::has('organization.flux') ? route('organization.flux', ['organization' => $organizationRouteParam]) : route('dashboard'), 'active' => 'flux', 'label' => __('navigation.feed'), 'icon' => config('navigation_icons.feed'), 'visible' => $canSeeFlux],
                ['key' => 'exchanges', 'url' => $tabUrl('explorer', 'organization.explorer'), 'active' => 'explorer', 'label' => __('navigation.exchanges'), 'icon' => config('navigation_icons.exchanges')],
                ['key' => 'messages', 'url' => $tabUrl('messages.index', 'organization.messages.index'), 'active' => 'messages', 'label' => __('navigation.messaging'), 'icon' => config('navigation_icons.messaging')],
                // TASK-1625 — les trois destinations que la barre fixe ne
                // pouvait pas porter. Route, libelle, icone et garde de
                // visibilite sont repris TELS QUELS du rail desktop
                // (`app-side-nav.blade.php`) : aucune route n'est creee, aucune
                // architecture d'information n'est inventee. Ce sont les memes
                // destinations, enfin atteignables sur telephone.
                ['key' => 'agenda', 'url' => $tabUrl('events.agenda', 'organization.events.agenda'), 'active' => 'events.agenda', 'label' => __('navigation.agenda'), 'icon' => config('navigation_icons.agenda'), 'visible' => $loopsEnabled],
                ['key' => 'members', 'url' => $tabUrl('members.index', 'organization.members.index'), 'active' => 'members', 'label' => __('navigation.directory'), 'icon' => config('navigation_icons.directory')],
                ['key' => 'dossiers', 'url' => $organizationRouteParam && Route::has('organization.dossiers.index') ? route('organization.dossiers.index', ['organization' => $organizationRouteParam]) : '#', 'active' => 'organization.dossiers', 'label' => __('navigation.my_dossiers'), 'icon' => config('navigation_icons.my_dossiers'), 'visible' => (bool) $organizationRouteParam && Route::has('organization.dossiers.index')],
                ['key' => 'blog', 'url' => $tabUrl('blog.index', 'organization.blog.index'), 'active' => 'blog', 'label' => __('navigation.blog'), 'icon' => config('navigation_icons.blog')],
            ] : [
                ['key' => 'loops', 'url' => $tabUrl('boucles.index', 'organization.boucles.index'), 'active' => 'boucles', 'label' => __('navigation.loops'), 'icon' => config('navigation_icons.loops')],
                ['key' => 'exchanges', 'url' => $tabUrl('explorer', 'organization.explorer'), 'active' => 'explorer', 'label' => __('navigation.exchanges'), 'icon' => config('navigation_icons.exchanges')],
                ['key' => 'members', 'url' => $tabUrl('members.index', 'organization.members.index'), 'active' => 'members', 'label' => __('navigation.directory'), 'icon' => config('navigation_icons.directory')],
                ['key' => 'blog', 'url' => $tabUrl('blog.index', 'organization.blog.index'), 'active' => 'blog', 'label' => __('navigation.blog'), 'icon' => config('navigation_icons.blog')],
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

    {{-- TASK-1625 — l'indice de defilement.

         Le masque de bord suggere que la bande continue ; cette fleche le DIT,
         brievement, au moment ou le doigt se pose. Elle ne se montre que s'il
         reste vraiment quelque chose a droite, s'efface au bout de 1,4 s, et
         ne capte aucun clic (`pointer-events-none`) : elle flotte au-dessus
         d'un onglet sans jamais le voler. --}}
    {{-- Une DIV, pas un SPAN, et c'est une histoire de mesure : la garde de
         TASK-1127 cherche les silhouettes decoratives par la signature
         `aria-hidden="true" … pointer-events-none`, puis lit leur contenu
         JUSQU'AU PREMIER `</div>`. Cet indice porte la meme signature — il est
         decoratif et inerte, legitimement — mais se fermait par `</span>` : la
         capture debordait alors sur le reste de la page et y trouvait des
         boutons qui ne lui appartiennent pas. Le fermer par `</div>` rend a
         cette garde la portion qu'elle croit lire. --}}
    <div x-show="indice"
          x-cloak
          x-transition:enter="transition ease-out duration-150"
          x-transition:enter-start="opacity-0 translate-x-1"
          x-transition:enter-end="opacity-100 translate-x-0"
          x-transition:leave="transition ease-in duration-300"
          x-transition:leave-start="opacity-100"
          x-transition:leave-end="opacity-0"
          data-bp-nav-scroll-hint
          aria-hidden="true"
          class="pointer-events-none absolute right-1 top-8 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-full bg-gray-900/75 text-white shadow-sm backdrop-blur-sm dark:bg-white/20">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
            <path d="M5 12h13m0 0-5-5m5 5-5 5" />
        </svg>
    </div>
</nav>
