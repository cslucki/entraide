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

        /* Le masquage de la barre applicative basse vit desormais dans le
           composant `x-discovery-bottom-nav`, avec la barre qui la remplace :
           les deux gestes sont indissociables et ne doivent pas diverger.

           NE JAMAIS ecrire ce nom entre chevrons ici : un commentaire CSS
           n'est pas un commentaire Blade. Blade a compile la chaine comme une
           VRAIE balise de composant, jamais refermee — d'ou un `if` sans
           `endif` et un 500 sur toute la page. */

        /* Plein ecran : l'element promu par l'API Fullscreen doit oublier la
           hauteur calculee de sa vignette, sinon il garde ses 100dvh MOINS
           l'en-tete au milieu d'un ecran noir. */
        [data-flowchart-stage]:fullscreen,
        [data-flowchart-stage]:-webkit-full-screen {
            width: 100vw;
            height: 100vh;
            max-height: none;
            margin: 0;
            border-radius: 0;
        }
    </style>

    <div class="flex flex-col">

        {{-- En-tete compact : il nomme la page et donne les commandes, puis
             cede toute la place au graphe. --}}
        <div class="px-4 pt-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-3">
                {{-- TASK-1609 — le titre ne s'ecrit qu'UNE fois.
                     `<x-app-layout title>` pose deja « Logigramme » dans la
                     barre d'application, visible en permanence sur mobile : ce
                     bloc le repetait juste en dessous. Il reste sur >= sm, ou
                     la barre d'application ne porte pas ce titre. --}}
                {{-- TASK-1609 — en-tete reduit au strict necessaire.

                     Arbitrage de Cyril : ni le nom « BouclePro » (le lecteur
                     sait ou il est), ni le sous-titre, ni le paragraphe
                     d'orchestration — trois blocs de texte qui repoussaient le
                     graphe vers le bas sans rien lui apprendre que la carte ne
                     dise mieux. Le graphe EST l'explication.

                     Le titre reste porte par la barre d'application sur
                     mobile, et par ce `h1` a partir de `sm` — ou cette barre
                     ne l'affiche pas. Il n'est donc jamais ecrit deux fois,
                     jamais zero fois. --}}
                <div class="hidden sm:block">
                    <h1 data-flowchart-heading class="text-xl font-semibold text-[var(--bp-text)] sm:text-2xl">{{ __('flowchart.title') }}</h1>
                </div>

                {{-- TASK-1609 — barre de transport, sur UNE seule ligne.
                     Les trois libelles se repliaient sur trois lignes en 390 px
                     et mangeaient le graphe. Chaque commande porte desormais un
                     picto ; le mot ne reapparait qu'a partir de `sm`, et la
                     rangee defile horizontalement si elle deborde. Le libelle
                     reste toujours lisible par un lecteur d'ecran via
                     `aria-label`. --}}
                <div class="-mx-4 flex w-full snap-x items-center gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:w-auto sm:overflow-visible sm:px-0 sm:pb-0"
                     role="group" aria-label="{{ __('flowchart.graph_label') }}">
                    @foreach([
                        ['overview', 'flowchart.overview', 'M4 5h6v6H4zM14 5h6v6h-6zM4 15h6v4H4zM14 15h6v4h-6z'],
                        ['back', 'flowchart.back', 'M15 6l-6 6 6 6'],
                        ['reset', 'flowchart.reset', 'M4 12a8 8 0 1 0 2.3-5.6M4 4v4h4'],
                        ['fullscreen', 'flowchart.fullscreen', 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5'],
                    ] as [$geste, $cle, $trace])
                        <button type="button" data-flowchart-{{ $geste }}
                                aria-label="{{ __($cle) }}" title="{{ __($cle) }}"
                                @if($geste === 'fullscreen')
                                    aria-pressed="false"
                                    data-label-enter="{{ __('flowchart.fullscreen') }}"
                                    data-label-exit="{{ __('flowchart.fullscreen_exit') }}"
                                @endif
                                class="inline-flex shrink-0 snap-start items-center gap-2 rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 px-3 py-2 text-sm font-medium text-[var(--bp-text)] transition hover:bg-[var(--bp-surface)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)] sm:px-4">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round"
                                 class="h-5 w-5 shrink-0" aria-hidden="true">
                                <path d="{{ $trace }}"></path>
                            </svg>
                            <span class="hidden sm:inline" data-flowchart-label>{{ __($cle) }}</span>
                        </button>
                    @endforeach
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
        {{-- La hauteur mobile RESERVE la barre de navigation basse.

             `x-mobile-bottom-nav` est `fixed bottom-0` et haute de 4rem, plus
             `env(safe-area-inset-bottom)`. Le conteneur de page porte bien
             `mobile-safe-bottom-auth`, mais ce padding agit en FIN de page :
             au premier ecran, la nav recouvrait donc les 54 derniers pixels du
             canvas — « Explorer les Boucles » passait dessous, mesure sur
             capture.

             La soustraction couvre l'en-tete, la nav et l'encoche. --}}
        {{-- TASK-1609 — l'en-tete mobile a maigri (titre dedouble retire, barre
             sur une ligne) : la soustraction passe de 20rem a 16rem et rend
             ces 64 px au graphe. `data-flowchart-stage` est aussi l'element
             qui passe en plein ecran. --}}
        <div data-flowchart-stage
             {{-- `--bp-page` et NON `--bp-bg` : ce dernier n'existe pas. Un
                  `var()` qui ne resout rien rend le fond transparent, et la
                  scene promue en plein ecran s'affichait alors sur le NOIR du
                  navigateur. Jetons verifies dans le style calcule, pas devines. --}}
             {{-- L'en-tete desktop a fondu (sous-titre et orchestration
                  retires) : la soustraction passe de 15rem a 11rem et rend ces
                  64 px au graphe. --}}
             class="relative mt-3 h-[calc(100dvh-16rem-env(safe-area-inset-bottom,0px))] min-h-[24rem] overflow-hidden bg-[var(--bp-page)] sm:h-[calc(100dvh-11rem)]">
            <div data-flowchart-canvas
                 role="application"
                 aria-label="{{ __('flowchart.graph_label') }}"
                 class="h-full w-full touch-none"></div>

            {{-- TASK-1609 — la sortie de plein ecran vit DANS la scene.
                 L'API Fullscreen ne rend que le sous-arbre de l'element promu :
                 la barre d'outils, qui est au-dessus, disparait entierement.
                 Un bouton de sortie place la-haut serait donc injoignable — et
                 sur un telephone il n'existe aucune touche Echap pour s'en
                 tirer. Mesure a l'appui : le clic de sortie expirait, le canvas
                 interceptant le pointeur.

                 Il ne s'affiche qu'en plein ecran (bascule par le moteur). --}}
            <button type="button" data-flowchart-fullscreen-exit
                    class="bp-invisible absolute right-3 top-3 z-20 items-center gap-2 rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)]/95 px-3 py-2 text-sm font-medium text-[var(--bp-text)] shadow-lg backdrop-blur transition hover:bg-[var(--bp-surface)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)]"
                    aria-label="{{ __('flowchart.fullscreen_exit') }}" title="{{ __('flowchart.fullscreen_exit') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 shrink-0" aria-hidden="true">
                    <path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"></path>
                </svg>
                <span class="hidden sm:inline">{{ __('flowchart.fullscreen_exit') }}</span>
            </button>

            {{-- §8 des correctifs — les commandes de zoom, visibles.
                 La molette et le pincement restent disponibles, mais ne sont
                 plus la seule methode : sur une tablette ou au pave tactile,
                 ils sont inegalement fiables. --}}
            {{-- TASK-1609 — en colonne, ces quatre boutons faisaient 160 px de
                 haut et recouvraient une carte du graphe sur la capture de
                 Cyril (« Explorer une idee »). En rangee sur mobile, l'amas
                 n'occupe plus qu'une bande basse de 44 px. --}}
            <div class="absolute bottom-3 left-3 z-10 flex flex-row gap-1 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-1 shadow-lg backdrop-blur sm:flex-col">
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

        {{-- LES ECHANGES REELS — Propositions et Demandes.

             Rendus par LARAVEL, masques par defaut, devoiles au clic sur le
             noeud correspondant. La garde n'est PAS ce `hidden` : pour un
             invite ou un visiteur d'un autre tenant, `FlowchartExchanges`
             n'emet meme pas la requete, et ces boucles `@foreach` tournent
             donc a vide. Il n'y a rien a cacher parce qu'il n'y a rien a
             recevoir — c'est la lecon de TASK-1488, dont le correctif avait
             mesure « 200 sans aucun cookie, avec le nom reel de la personne ».

             Memes regles metier qu'Explorer : `Service::active()` et
             `ServiceRequest::open()`, bornees a cette Organization. --}}
        @foreach([
            ['proposals', $proposals, 'flowchart.outlet_need_help', 'flowchart.cta_view_proposal'],
            ['requests', $requests, 'flowchart.outlet_offer_help', 'flowchart.cta_view_request'],
        ] as [$quoi, $cartes, $titre, $cta])
            <div data-flowchart-echanges="{{ $quoi }}" hidden
                 class="border-t border-[var(--bp-border)] px-4 py-6 sm:px-6 lg:px-8">
                <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __($titre) }}</h2>

                {{-- Le meme etat que celui qui a decide du chargement : la zone
                     ne peut donc pas dire autre chose que ce que le serveur a
                     fait. --}}
                @if(! $isOrganizationMember)
                    <p class="mt-3 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/60 px-4 py-3 text-sm text-[var(--bp-muted)]">
                        {{ $graph['access_state'] === 'guest'
                            ? __('flowchart.outlet_state_guest')
                            : __('flowchart.outlet_state_outsider') }}
                    </p>
                @elseif($cartes->isEmpty())
                    <p class="mt-3 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/60 px-4 py-3 text-sm text-[var(--bp-muted)]">
                        {{ __('flowchart.outlet_empty') }}
                    </p>
                @else
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($cartes as $carte)
                            <article class="flex h-full flex-col rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/70 p-5">
                                @if($carte['category'])
                                    <span class="w-fit rounded-full bg-[color-mix(in_srgb,var(--bp-info)_15%,transparent)] px-3 py-1 text-xs font-semibold text-[var(--bp-info)]">
                                        {{ $carte['category'] }}
                                    </span>
                                @endif
                                <h3 class="mt-3 text-base font-semibold text-[var(--bp-text)]">{{ $carte['title'] }}</h3>
                                @if(filled($carte['excerpt']))
                                    <p class="mt-2 text-sm leading-6 text-[var(--bp-muted)]">{{ $carte['excerpt'] }}</p>
                                @endif
                                <p class="mt-3 text-xs text-[var(--bp-muted)]">{{ $carte['author'] }}</p>
                                <a href="{{ $carte['url'] }}"
                                   class="mt-4 inline-flex w-fit items-center justify-center rounded-full border border-[var(--bp-primary)] px-4 py-2 text-sm font-semibold text-[var(--bp-primary)] transition hover:bg-[var(--bp-primary)] hover:text-white">
                                    {{ __($cta) }}
                                </a>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        {{-- LA VERSION TEXTE — une ALTERNATIVE, pas une seconde interface.

             Version precedente : quatre sections de cards, qui refaisaient le
             logigramme en bas de page. MASTER l'a rejetee — le bas de page
             devenait une seconde presentation du produit.

             Ici : HTML semantique, replie par defaut, lisible en moins d'une
             minute. Elle lit `$graph`, donc elle ne peut pas montrer une
             Boucle que la carte cache : memes regles de visibilite, meme
             source, aucune logique en double. --}}
        @php
            $parGenre = collect($graph['nodes'])->groupBy(fn (array $n): string => $n['data']['kind']);
            $entrees = $parGenre->get('entry', collect())->keyBy(fn (array $n): string => $n['data']['id']);
            $bouclesTexte = $parGenre->get('loop', collect());
        @endphp

        <div class="px-4 py-6 sm:px-6 lg:px-8">
            <details class="rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)]/50 px-5 py-4">
                <summary class="cursor-pointer text-sm font-semibold text-[var(--bp-text)]">
                    {{ __('flowchart.fallback_title') }}
                </summary>

                <div class="mt-4 space-y-5 text-sm leading-6 text-[var(--bp-muted)]">

                    <section>
                        <h2 class="text-sm font-semibold text-[var(--bp-text)]">{{ __('flowchart.cards_intents_title') }}</h2>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach($parGenre->get('intent', collect()) as $intention)
                                @php $entree = $entrees->get(str_replace('intent:', 'entry:', $intention['data']['id'])); @endphp
                                <li>
                                    <span class="font-medium text-[var(--bp-text)]">{{ $intention['data']['label'] }}</span>
                                    @if($entree) — {{ $entree['data']['label'] }} @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section>
                        <h2 class="text-sm font-semibold text-[var(--bp-text)]">{{ __('flowchart.cards_engine_title') }}</h2>
                        {{-- Le moteur condense : la chaine, pas huit cards. --}}
                        <p class="mt-2">
                            {{ collect([
                                'step_clarify', 'step_match', 'step_exchange',
                                'aggregate_engine', 'step_synthesis', 'step_decision',
                            ])->map(fn (string $cle): string => __('flowchart.'.$cle))->join(' → ') }}
                        </p>
                    </section>

                    <section>
                        <h2 class="text-sm font-semibold text-[var(--bp-text)]">{{ __('flowchart.cards_outcomes_title') }}</h2>
                        <p class="mt-2">
                            {{ $parGenre->get('outcome', collect())->pluck('data.label')->join(' · ') }}
                        </p>
                    </section>

                    <section>
                        <h2 class="text-sm font-semibold text-[var(--bp-text)]">{{ __('flowchart.cards_loops_title') }}</h2>

                        @if($bouclesTexte->isEmpty())
                            <p class="mt-2">{{ $graph['is_guest'] ? __('flowchart.guest_hint') : __('flowchart.explore_loops_empty') }}</p>
                        @else
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach($bouclesTexte as $boucle)
                                    <li>
                                        <a href="{{ $boucle['data']['url'] }}"
                                           class="font-medium text-[var(--bp-primary)] hover:underline">{{ $boucle['data']['name'] }}</a>
                                        — {{ $boucle['data']['type_label'] }} · {{ $boucle['data']['access_label'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
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
        {{-- TASK-1609 — le pied classique est une colonne de liens longue : sur
             un telephone il repoussait le graphe et doublonnait avec la barre
             basse. Il reste ENTIER a partir de `md`. --}}
        <div class="hidden md:block">
            @include('partials.footer')
        </div>
    </div>

    {{-- TASK-1609 — la barre basse de decouverte, partagee avec « A propos »
         et « Mycelium ». Elle REMPLACE `x-mobile-bottom-nav` : sur ces trois
         pages, un visiteur n'a que faire des onglets applicatifs. --}}
    <x-discovery-bottom-nav :organization="$organization" />
    {{-- Le payload. `@json` echappe `<`, `>`, `&`, `'` et `"` : un nom de
         Boucle ecrit par un membre ne peut pas refermer ce bloc. --}}
    <script type="application/json" data-flowchart-graph>@json($graph)</script>

    {{-- Entree Vite DEDIEE, chargee par cette seule page — meme patron que
         `deep-chat-init.js` sur l'editeur de blog. Cytoscape (~440 Ko) ne
         touche donc AUCUNE autre page du produit. --}}
    @vite(['resources/js/flowchart.js'])
</x-app-layout>
