{{--
    TASK-1608 — la carte interactive de l'Organization.

    ## Ce que cette vue ne fait pas

    Elle ne decide AUCUNE visibilite. Le tableau `$graph` arrive deja filtre par
    `App\Support\Flowchart\FlowchartLoops`. Une Boucle absente du payload l'est
    du DOM, du JSON et des attributs `data-*` : il n'y a rien a cacher ici,
    parce qu'il n'y a rien a recevoir.

    ## Le graphe EST la page (addendum MASTER, recette visuelle)

    Premiere version : un `max-w-6xl` centre, un canvas de 26-38rem pose sur un
    panneau opaque `--bp-panel` borde. Rendu mesure par MASTER : « une petite
    carte blanche perdue au milieu d'un grand espace vide ».

    Corrige : plus de largeur maximale, plus de panneau opaque, plus de cadre.
    Le canvas occupe la zone de contenu entiere a droite du rail, sur une
    hauteur `100dvh` moins l'en-tete et le pied. Le fond reste celui du layout
    (`--bp-page`) parce que le canvas est TRANSPARENT : Cytoscape n'y peint
    aucune couleur de fond, et le graphe appartient visuellement a
    l'application au lieu d'y etre embarque.

    ## Les couleurs viennent du theme, pas d'un slug

    Aucune couleur n'est ecrite en dur et aucun `if launchpals` n'existe. Les
    jetons `--bp-*` sont poses par le layout (`<x-theme-tokens>`), et le moteur
    Cytoscape les lit a l'execution via `getComputedStyle`.

    ## Le graphe n'est pas le seul chemin (§15)

    Le repli textuel rend les MEMES noeuds, dans l'ordre, pour un lecteur
    d'ecran ou un navigateur sans canvas. Il lit `$graph` : il ne peut pas
    diverger de ce que la carte montre.
--}}
<x-app-layout title="{{ __('flowchart.title') }}">

    <div class="flex flex-col">

        {{-- En-tete compact : il nomme la page et donne les commandes, puis
             cede toute la place au graphe. --}}
        <div class="px-4 pt-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--bp-primary)]">
                        {{ $organization->name }}
                    </p>
                    <h1 class="mt-1 text-xl font-semibold text-[var(--bp-text)] sm:text-2xl">{{ __('flowchart.title') }}</h1>
                    <p class="mt-1 max-w-2xl text-sm text-[var(--bp-muted)]">{{ __('flowchart.subtitle') }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('flowchart.graph_label') }}">
                    <button type="button" data-flowchart-overview
                            class="rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 px-4 py-2 text-sm font-medium text-[var(--bp-text)] transition hover:bg-[var(--bp-surface)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]">
                        {{ __('flowchart.overview') }}
                    </button>
                    <button type="button" data-flowchart-back
                            class="rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 px-4 py-2 text-sm font-medium text-[var(--bp-text)] transition hover:bg-[var(--bp-surface)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]">
                        {{ __('flowchart.back') }}
                    </button>
                    <button type="button" data-flowchart-reset
                            class="rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 px-4 py-2 text-sm font-medium text-[var(--bp-text)] transition hover:bg-[var(--bp-surface)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]">
                        {{ __('flowchart.reset') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- LA SURFACE DOMINANTE.

             La hauteur est EXPLICITE, et ce n'est pas un detail de style : la
             premiere version portait `flex-1` + `h-full` sous un parent en
             `min-h-[100dvh]`. Une hauteur en pourcentage exige une hauteur
             DEFINIE chez le parent — `min-height` n'en est pas une. `h-full`
             ne resolvait donc rien, et c'est `min-h-[30rem]` qui gagnait :
             480 px mesures a 1440x900, d'ou « le graphe n'occupe qu'une petite
             partie de l'espace ».

             `calc(100dvh - …)` retire l'en-tete reel. `dvh` plutot que `vh`
             pour que la barre d'adresse mobile ne rogne pas le canvas.

             Le pied vient ENSUITE dans le flux : on le rejoint en defilant,
             comme MASTER le demande.

             `overflow-hidden` borne Cytoscape a son cadre : le canvas se
             navigue, la PAGE ne defile jamais horizontalement (§H). --}}
        <div class="relative mt-3 h-[calc(100dvh-17rem)] min-h-[30rem] overflow-hidden sm:h-[calc(100dvh-14rem)]">
            <div data-flowchart-canvas
                 role="application"
                 aria-label="{{ __('flowchart.graph_label') }}"
                 class="h-full w-full touch-none"></div>

            {{-- Le detail contextuel (§10), pose EN SURIMPRESSION : il ne
                 retire pas un pouce de largeur au graphe. Carte flottante a
                 droite sur desktop, bandeau bas sur mobile. --}}
            <aside data-flowchart-panel hidden
                   class="absolute inset-x-3 bottom-3 z-10 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/95 p-4 shadow-lg backdrop-blur sm:inset-x-auto sm:right-4 sm:top-4 sm:bottom-auto sm:w-80">
                <button type="button" data-flowchart-panel-close
                        class="absolute right-3 top-3 text-[var(--bp-muted)] transition hover:text-[var(--bp-text)]"
                        aria-label="{{ __('flowchart.back') }}">&times;</button>
                <p data-flowchart-panel-kind class="pr-6 text-xs font-semibold uppercase tracking-[0.18em] text-[var(--bp-primary)]"></p>
                <h2 data-flowchart-panel-title class="mt-1 pr-6 text-base font-semibold text-[var(--bp-text)]"></h2>
                <p data-flowchart-panel-body class="mt-2 text-sm leading-6 text-[var(--bp-muted)]"></p>
                <p data-flowchart-panel-meta class="mt-2 text-xs text-[var(--bp-muted)]"></p>
                <a data-flowchart-panel-cta hidden
                   class="mt-3 inline-flex items-center justify-center rounded-full bg-[var(--bp-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[var(--bp-primary-deep)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]"></a>
            </aside>

            @if($graph['is_guest'])
                <p class="pointer-events-none absolute inset-x-3 top-3 z-0 mx-auto w-fit rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)]/85 px-4 py-2 text-center text-xs text-[var(--bp-muted)] backdrop-blur"
                   data-flowchart-guest-hint>
                    {{ __('flowchart.guest_hint') }}
                </p>
            @endif
        </div>

        {{-- §15 — le repli. Les MEMES noeuds, lisibles sans le graphe. --}}
        <div class="px-4 pb-4 sm:px-6 lg:px-8">
            <details class="rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/60 p-4">
                <summary class="cursor-pointer text-sm font-semibold text-[var(--bp-text)]">{{ __('flowchart.fallback_title') }}</summary>
                <p class="mt-2 text-sm text-[var(--bp-muted)]">{{ __('flowchart.fallback_intro') }}</p>

                <ul class="mt-4 space-y-2 text-sm text-[var(--bp-muted)]">
                    @foreach($graph['nodes'] as $node)
                        @continue($node['data']['kind'] === 'loop')
                        <li>
                            <span class="font-medium text-[var(--bp-text)]">{{ $node['data']['label'] }}</span>
                            @isset($node['data']['hint'])
                                — {{ $node['data']['hint'] }}
                            @endisset
                        </li>
                    @endforeach
                </ul>

                @if($graph['has_loops'])
                    <h3 class="mt-5 text-sm font-semibold text-[var(--bp-text)]">{{ __('flowchart.fallback_loops') }}</h3>
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach($graph['nodes'] as $node)
                            @continue($node['data']['kind'] !== 'loop')
                            <li>
                                <a href="{{ $node['data']['url'] }}"
                                   class="font-medium text-[var(--bp-primary)] hover:underline">{{ $node['data']['label'] }}</a>
                                <span class="text-[var(--bp-muted)]">— {{ $node['data']['access_label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 text-sm text-[var(--bp-muted)]">{{ __('flowchart.explore_loops_empty') }}</p>
                @endif
            </details>
        </div>

        {{-- Addendum MASTER A — le MEME pied que les autres surfaces
             d'Organization. `@include` plutot qu'une copie : le HTML n'est pas
             duplique, et les liens restent Organization-scoped, logigramme
             compris.

             `x-app-layout` n'inclut aucun pied (ses liens legaux vivent dans
             la nav laterale) : il est donc pose ICI, pour cette page, plutot
             que dans le layout — ce qui changerait TOUTES les pages
             applicatives, ce que l'addendum ne demande pas. --}}
        @include('partials.footer')
    </div>

    {{-- Le payload. `@json` echappe `<`, `>`, `&`, `'` et `"` : un nom de
         Boucle ecrit par un membre ne peut pas refermer ce bloc. --}}
    <script type="application/json" data-flowchart-graph>@json($graph)</script>

    {{-- Entree Vite DEDIEE, chargee par cette seule page — meme patron que
         `deep-chat-init.js` sur l'editeur de blog. Cytoscape (~440 Ko) ne
         touche donc AUCUNE autre page du produit. --}}
    @vite(['resources/js/flowchart.js'])
</x-app-layout>
