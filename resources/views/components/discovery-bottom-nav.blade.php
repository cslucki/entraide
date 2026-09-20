{{--
    TASK-1609 — la barre basse des pages de DECOUVERTE.

    Trois surfaces expliquent le produit a quelqu'un qui ne le connait pas
    encore : « A propos », « Mycelium » et « Logigramme ». Sur telephone, elles
    portaient les onglets applicatifs (Boucles / Echanges / Annuaire / Blog) —
    une navigation de MEMBRE, sans objet pour un visiteur qui decouvre. Cette
    barre les remplace par les quatre destinations de la decouverte.

    Elle s'affiche sur telephone UNIQUEMENT (`md:hidden`, le meme point d'arret
    que la barre remplacee, pour qu'il n'existe aucune largeur ou les deux
    coexistent ou disparaissent), et QUE L'ON SOIT CONNECTE OU NON : c'est une
    aide a la decouverte, pas un etat de session.

    Elle est `fixed bottom-0` — elle reste donc collee en bas pendant le
    defilement, comme la barre qu'elle remplace.
--}}
@php
    // L'Organization du contexte. Sur la route GLOBALE `/mycelium`, il peut n'y
    // en avoir aucune : la barre ne se rend alors pas du tout, plutot que de
    // batir des liens vers une Organization devinee.
    $organisationBarre = $organization ?? currentOrganization();
    $routeCourante = request()->route()?->getName() ?? '';

    // Les quatre destinations, verifiees dans `route:list` — aucune inventee.
    //
    // `public.demo` est GLOBALE, et c'est VOULU : `bouclepro.com/demo` est une
    // REDIRECTION vers le prototype heberge ailleurs
    // (`lastprod.com/bouclepro-prototype/`). La demo ne vit pas dans ce produit
    // et n'a donc aucune variante par Organization. C'est une exception
    // DECLAREE a la doctrine TASK-1608 (« aucune route globale depuis une
    // surface Organization-scoped »), pas un oubli de portee : ne pas la
    // « corriger » en inventant `organization.demo`.
    $destinations = $organisationBarre ? [
        ['organization.about', true, __('flowchart.nav_about'), 'M12 16v-4M12 8h.01', true],
        ['public.demo', false, __('flowchart.nav_demo'), 'M8 5l10 7-10 7z', false],
        ['organization.flowchart', true, __('flowchart.nav_flowchart'), 'M5 4h5v4H5zM14 4h5v4h-5zM9 16h6v4H9zM12 8v4m-5 0h10v4', false],
        ['organization.mycelium', true, __('flowchart.nav_mycelium'), 'M12 3v6m0 0l-4 3m4-3l4 3M8 12v6m8-6v6', false],
    ] : [];
@endphp

@if($destinations)
    {{-- Feuille AUTOPORTANTE, en CSS pur.

         Ce composant est monte aussi sur « A propos », qui est un document
         HTML autonome SANS Tailwind : les classes utilitaires n'y produisent
         rien. Mesure a l'appui, la barre y tombait en `position: static` —
         ni collee en bas, ni masquee sur desktop.

         Le fond etait par ailleurs transparent partout : `bg-[var(--x)]/95`
         n'emet aucune couleur, le modificateur d'opacite ne sachant pas
         decomposer une variable. On ecrit donc la couleur pleine. --}}
    <style>
        /* La barre applicative basse cede la place a celle-ci. Cette feuille
           n'est emise que par les pages qui montent ce composant : aucune autre
           surface n'est touchee, et le composant partage n'est pas modifie. */
        [data-bp-mobile-nav] { display: none !important; }

        .bp-discovery-nav {
            position: fixed;
            inset-inline: 0;
            bottom: 0;
            z-index: 40;
            border-top: 1px solid var(--bp-border, #24427F);
            background: var(--bp-surface, #0A2058);
            padding-bottom: env(safe-area-inset-bottom, 0px);
        }

        /* Telephone UNIQUEMENT — meme point d'arret que la barre remplacee. */
        @media (min-width: 768px) { .bp-discovery-nav { display: none; } }

        .bp-discovery-nav__row {
            display: flex; align-items: center; justify-content: space-around;
            height: 4rem; padding-inline: 0.5rem;
        }

        .bp-discovery-nav a {
            display: flex; flex-direction: column; align-items: center; gap: 0.25rem;
            flex: 1 1 0%; min-width: 0;
            padding: 0.25rem;
            border-radius: 0.75rem;
            font-size: 0.6875rem; font-weight: 500; line-height: 1.1;
            text-decoration: none;
            color: var(--bp-muted, #C7D2FE);
            transition: color .15s ease;
        }

        .bp-discovery-nav a:hover { color: var(--bp-text, #F8FAFC); }
        .bp-discovery-nav a[aria-current="page"] { color: var(--bp-primary, #527DFF); }
        .bp-discovery-nav svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
        .bp-discovery-nav span {
            width: 100%; text-align: center;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
    </style>

    <nav data-discovery-nav class="bp-discovery-nav" aria-label="{{ __('flowchart.nav_label') }}">
        <div class="bp-discovery-nav__row">
            @foreach($destinations as [$route, $scopee, $libelle, $trace, $cercle])
                @php
                    $href = $scopee ? route($route, $organisationBarre) : route($route);
                    $ici = $routeCourante === $route;
                @endphp
                <a href="{{ $href }}" @if($ici) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        @if($cercle)<circle cx="12" cy="12" r="9"></circle>@endif
                        <path d="{{ $trace }}"></path>
                    </svg>
                    <span>{{ $libelle }}</span>
                </a>
            @endforeach
        </div>
    </nav>
@endif
