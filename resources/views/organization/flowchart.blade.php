{{--
    TASK-1608 — la carte interactive de l'Organization.

    ## Ce que cette vue ne fait pas

    Elle ne decide AUCUNE visibilite. Le tableau `$graph` arrive deja filtre par
    `App\Support\Flowchart\FlowchartLoops`. Une Boucle absente du payload l'est
    du DOM, du JSON et des attributs `data-*` : il n'y a rien a cacher ici,
    parce qu'il n'y a rien a recevoir.

    ## Le graphe EST la page

    Pas de largeur maximale, pas de panneau opaque, pas de cadre. Le canvas
    occupe la zone de contenu a droite du rail, sur une hauteur `100dvh` moins
    l'en-tete. Le fond reste celui du layout (`--bp-page`) parce que le canvas
    est TRANSPARENT : le graphe appartient a l'application au lieu d'y etre
    embarque.

    La hauteur est EXPLICITE. Une version precedente portait `flex-1` + `h-full`
    sous un parent en `min-h` : une hauteur en pourcentage exige une hauteur
    DEFINIE, `min-height` n'en est pas une, et c'est `min-h-[30rem]` qui
    gagnait — 480 px mesures a 1440x900.

    ## Le parcours en texte n'est pas un repli au rabais (§11 des correctifs)

    La liste a plat est remplacee par quatre sections de cartes, qui suivent la
    meme grammaire que le graphe : les quatre portes, le moteur commun, ce que
    cela produit, les Boucles reelles. Elle lit `$graph` : elle ne peut pas
    diverger de ce que la carte montre, regles de visibilite comprises.
--}}
<x-app-layout title="{{ __('flowchart.title') }}">

    <style>
        /* TASK-1608 §10 des correctifs — la bascule du CTA du panneau.

           `hidden` seul ne suffisait pas : Tailwind ecrit `[hidden]{display:none}`
           dans preflight, donc AVANT les utilitaires. A specificite egale
           (0,1,0 contre 0,1,0), `.inline-flex` gagnait par ordre de source, et
           une pastille vide restait visible sous le texte du panneau.

           Une classe dediee, avec `!important`, ne depend plus de cet ordre. */
        .bp-invisible { display: none !important; }
    </style>

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

            {{-- §9 des correctifs — le bandeau invite a SA zone, dans le flux.
                 En surimpression sur le canvas, il recouvrait un noeud des que
                 la branche remontait. --}}
            @if($graph['is_guest'])
                <p class="mt-3 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 px-4 py-2 text-sm text-[var(--bp-muted)]"
                   data-flowchart-guest-hint>
                    {{ __('flowchart.guest_hint') }}
                </p>
            @endif
        </div>

        {{-- LA SURFACE DOMINANTE.

             `overflow-hidden` borne Cytoscape a son cadre : le canvas se
             navigue, la PAGE ne defile jamais horizontalement (§13). --}}
        <div class="relative mt-3 h-[calc(100dvh-19rem)] min-h-[30rem] overflow-hidden sm:h-[calc(100dvh-15rem)]">
            <div data-flowchart-canvas
                 role="application"
                 aria-label="{{ __('flowchart.graph_label') }}"
                 class="h-full w-full touch-none"></div>

            {{-- §8 des correctifs — les commandes de zoom, visibles.
                 La molette et le pincement restent disponibles, mais ne sont
                 plus la seule methode : sur une tablette ou au pave tactile,
                 ils sont inegalement fiables. --}}
            <div class="absolute bottom-3 left-3 z-10 flex flex-col gap-1 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-1 shadow-lg backdrop-blur">
                @foreach([
                    ['zoom-in', '+', 'flowchart.zoom_in'],
                    ['zoom-out', '−', 'flowchart.zoom_out'],
                    ['recenter', '⊙', 'flowchart.recenter'],
                    ['fit', '⤢', 'flowchart.fit'],
                ] as [$geste, $glyphe, $cle])
                    <button type="button" data-flowchart-{{ $geste }}
                            aria-label="{{ __($cle) }}" title="{{ __($cle) }}"
                            class="flex h-9 w-9 items-center justify-center rounded-xl text-base font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-surface-soft)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]">
                        {{ $glyphe }}
                    </button>
                @endforeach
            </div>

            {{-- Le detail contextuel (§10), pose EN SURIMPRESSION : il ne
                 retire pas un pouce de largeur au graphe. Carte flottante a
                 droite sur desktop, bandeau bas sur mobile. Le moteur centre
                 le noeud actif dans la zone RESTANTE, jamais derriere lui. --}}
            <aside data-flowchart-panel hidden
                   class="absolute inset-x-3 bottom-3 z-20 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/95 p-4 shadow-lg backdrop-blur sm:inset-x-auto sm:right-4 sm:top-4 sm:bottom-auto sm:w-80">
                <button type="button" data-flowchart-panel-close
                        class="absolute right-3 top-3 text-lg leading-none text-[var(--bp-muted)] transition hover:text-[var(--bp-text)]"
                        aria-label="{{ __('flowchart.back') }}">&times;</button>
                <p data-flowchart-panel-kind class="pr-6 text-xs font-semibold uppercase tracking-[0.18em] text-[var(--bp-primary)]"></p>
                <h2 data-flowchart-panel-title class="mt-1 pr-6 text-base font-semibold text-[var(--bp-text)]"></h2>
                <p data-flowchart-panel-body class="mt-2 text-sm leading-6 text-[var(--bp-muted)]"></p>
                <p data-flowchart-panel-meta class="mt-2 text-xs text-[var(--bp-muted)]"></p>
                <a data-flowchart-panel-cta
                   class="bp-invisible mt-3 inline-flex items-center justify-center rounded-full bg-[var(--bp-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[var(--bp-primary-deep)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]"></a>
            </aside>
        </div>

        {{-- §11 des correctifs — le parcours en CARTES, pas une liste a plat. --}}
        @php
            $parGenre = collect($graph['nodes'])->groupBy(fn (array $n): string => $n['data']['kind']);
            $intentions = $parGenre->get('intent', collect());
            $entrees = $parGenre->get('entry', collect())->keyBy(fn (array $n): string => $n['data']['id']);
            $etapes = $parGenre->get('step', collect());
            $resultats = $parGenre->get('outcome', collect());
            $bouclesTexte = $parGenre->get('loop', collect());
        @endphp

        <div class="space-y-8 px-4 py-8 sm:px-6 lg:px-8">

            {{-- SECTION 1 — les quatre portes, chacune avec SA premiere etape. --}}
            <section>
                <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __('flowchart.cards_intents_title') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($intentions as $intention)
                        @php $entree = $entrees->get(str_replace('intent:', 'entry:', $intention['data']['id'])); @endphp
                        <article class="flex h-full flex-col rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 p-5">
                            <span class="h-1 w-10 rounded-full bg-[var(--bp-primary)]" aria-hidden="true"></span>
                            <h3 class="mt-3 text-base font-semibold text-[var(--bp-text)]">{{ $intention['data']['label'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-[var(--bp-muted)]">{{ $intention['data']['hint'] ?? '' }}</p>
                            @if($entree)
                                <p class="mt-3 border-t border-[var(--bp-border)] pt-3 text-sm font-medium text-[var(--bp-primary)]">
                                    {{ $entree['data']['label'] }}
                                </p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- SECTION 2 — le moteur commun, numerote parce qu'il a un ordre. --}}
            <section>
                <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __('flowchart.cards_engine_title') }}</h2>
                <ol class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($etapes as $rang => $etape)
                        <li class="flex h-full flex-col rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 p-5">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--bp-info)_18%,transparent)] text-sm font-semibold text-[var(--bp-info)]">
                                {{ $rang + 1 }}
                            </span>
                            <h3 class="mt-3 text-base font-semibold text-[var(--bp-text)]">{{ $etape['data']['label'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-[var(--bp-muted)]">{{ $etape['data']['hint'] ?? '' }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- SECTION 3 — ce que cela produit. --}}
            <section>
                <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __('flowchart.cards_outcomes_title') }}</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    @foreach($resultats as $resultat)
                        <p class="rounded-2xl border border-[var(--bp-border)] bg-[color-mix(in_srgb,var(--bp-validation)_10%,transparent)] px-4 py-3 text-sm font-medium text-[var(--bp-text)]">
                            {{ $resultat['data']['label'] }}
                        </p>
                    @endforeach
                </div>
            </section>

            {{-- SECTION 4 — les Boucles REELLES, memes regles de visibilite. --}}
            <section>
                <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __('flowchart.cards_loops_title') }}</h2>

                @if($bouclesTexte->isEmpty())
                    <p class="mt-4 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/60 px-4 py-3 text-sm text-[var(--bp-muted)]">
                        {{ $graph['is_guest'] ? __('flowchart.guest_hint') : __('flowchart.explore_loops_empty') }}
                    </p>
                @else
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($bouclesTexte as $boucle)
                            <article class="flex h-full flex-col rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 p-5">
                                <span class="w-fit rounded-full bg-[color-mix(in_srgb,var(--bp-accent)_15%,transparent)] px-3 py-1 text-xs font-semibold text-[var(--bp-accent)]">
                                    {{ $boucle['data']['access_label'] }}
                                </span>
                                <h3 class="mt-3 text-base font-semibold text-[var(--bp-text)]">{{ $boucle['data']['name'] }}</h3>
                                @if(filled($boucle['data']['tagline'] ?? null))
                                    <p class="mt-2 text-sm leading-6 text-[var(--bp-muted)]">{{ $boucle['data']['tagline'] }}</p>
                                @endif
                                <a href="{{ $boucle['data']['url'] }}"
                                   class="mt-4 inline-flex w-fit items-center justify-center rounded-full border border-[var(--bp-primary)] px-4 py-2 text-sm font-semibold text-[var(--bp-primary)] transition hover:bg-[var(--bp-primary)] hover:text-white">
                                    {{ $boucle['data']['cta_label'] }}
                                </a>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
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
