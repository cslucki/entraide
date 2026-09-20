{{--
    TASK-1608 — la carte interactive de l'Organization.

    ## Ce que cette vue ne fait pas

    Elle ne decide AUCUNE visibilite. Le tableau `$graph` arrive deja filtre par
    `App\Support\Flowchart\FlowchartGraph`, qui consomme `VisibleLoops`. Une
    Boucle absente du payload l'est du DOM, du JSON et des attributs `data-*` :
    il n'y a rien a cacher ici, parce qu'il n'y a rien a recevoir.

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
    <x-page-container>
        <div class="mx-auto max-w-6xl">

            <header class="mb-6">
                <p class="mb-3 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)]">
                    {{ $organization->name }}
                </p>
                <h1 class="text-2xl font-semibold text-[var(--bp-text)] md:text-3xl">{{ __('flowchart.title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--bp-muted)]">{{ __('flowchart.subtitle') }}</p>
            </header>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('flowchart.graph_label') }}">
                <button type="button" data-flowchart-overview
                        class="rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)] px-4 py-2 text-sm font-medium text-[var(--bp-text)] transition hover:bg-[var(--bp-surface-soft)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]">
                    {{ __('flowchart.overview') }}
                </button>
                <button type="button" data-flowchart-back
                        class="rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)] px-4 py-2 text-sm font-medium text-[var(--bp-text)] transition hover:bg-[var(--bp-surface-soft)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]">
                    {{ __('flowchart.back') }}
                </button>
                <button type="button" data-flowchart-reset
                        class="rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)] px-4 py-2 text-sm font-medium text-[var(--bp-text)] transition hover:bg-[var(--bp-surface-soft)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]">
                    {{ __('flowchart.reset') }}
                </button>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">

                {{-- Le canvas. `touch-action: none` laisse Cytoscape gerer le
                     pincement sans que la PAGE defile sous le doigt (§14). --}}
                <div class="relative overflow-hidden rounded-[1.75rem] border border-[var(--bp-border)] bg-[var(--bp-panel)] shadow-sm">
                    <div data-flowchart-canvas
                         role="application"
                         aria-label="{{ __('flowchart.graph_label') }}"
                         class="h-[26rem] w-full touch-none sm:h-[32rem] lg:h-[38rem]"></div>
                </div>

                {{-- Le detail contextuel (§10) : le graphe reste simple, la
                     fiche vit ici. Masquee tant que rien n'est selectionne. --}}
                <aside data-flowchart-panel hidden
                       class="rounded-[1.75rem] border border-[var(--bp-border)] bg-[var(--bp-surface)] p-5 shadow-sm">
                    <p data-flowchart-panel-kind class="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--bp-primary)]"></p>
                    <h2 data-flowchart-panel-title class="mt-2 text-lg font-semibold text-[var(--bp-text)]"></h2>
                    <p data-flowchart-panel-body class="mt-2 text-sm leading-6 text-[var(--bp-muted)]"></p>
                    <p data-flowchart-panel-meta class="mt-3 text-xs text-[var(--bp-muted)]"></p>
                    <a data-flowchart-panel-cta hidden
                       class="mt-4 inline-flex items-center justify-center rounded-full bg-[var(--bp-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[var(--bp-primary-deep)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]"></a>
                </aside>
            </div>

            @if($graph['is_guest'])
                <p class="mt-4 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface-soft)] px-4 py-3 text-sm text-[var(--bp-muted)]"
                   data-flowchart-guest-hint>
                    {{ __('flowchart.guest_hint') }}
                </p>
            @elseif(! $graph['has_loops'])
                <p class="mt-4 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface-soft)] px-4 py-3 text-sm text-[var(--bp-muted)]"
                   data-flowchart-empty-hint>
                    {{ __('flowchart.explore_loops_empty') }}
                </p>
            @endif

            {{-- §15 — le repli. Les MEMES noeuds, lisibles sans le graphe. --}}
            <details class="mt-6 rounded-[1.75rem] border border-[var(--bp-border)] bg-[var(--bp-surface)] p-5">
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
                @endif
            </details>
        </div>
    </x-page-container>

    {{-- Le payload. `@json` echappe `<`, `>`, `&`, `'` et `"` : un nom de
         Boucle ecrit par un membre ne peut pas refermer ce bloc. --}}
    <script type="application/json" data-flowchart-graph>@json($graph)</script>

    {{-- Entree Vite DEDIEE, chargee par cette seule page — meme patron que
         `deep-chat-init.js` sur l'editeur de blog. Cytoscape (~434 Ko) ne
         touche donc AUCUNE autre page du produit. --}}
    @vite(['resources/js/flowchart.js'])
</x-app-layout>
