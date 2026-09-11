{{--
    AI Context Inspector (TASK-1533) — l'instrument d'observation du BouclePro
    Nervous System.

    L'ecran repond a « pourquoi BouclePro a-t-il produit CETTE reponse ? ». Il
    n'execute rien lui-meme : la question part sur le pipeline canonique
    (`OrganizationDoctrineSandbox`), et cette page relit ce que le pipeline et
    le ledger canonique ont ecrit.

    ## Pourquoi cette composition, et pas une pile de cartes

    Une pile « formulaire puis resultats » ne dit qu'une chose : un essai a eu
    lieu. Ici la question n'est qu'une des quatre zones, et les trois autres
    existent AVANT elle : ce qui gouverne (gauche), ce qui s'execute (droite),
    ce qui a ete lu (bas). L'ecran a vide est donc deja une reponse — « voila
    l'architecture qui repondra » — et un tour ne fait que l'allumer.

    ## Ce que la carte de gauche a le droit d'affirmer

    Rien qu'elle n'ait mesure. La gouvernance vient de `NervousSystemMap`, qui
    derive `locked`/`configurable` de l'existence d'une route d'ecriture. Les
    sources viennent des `allowedSources` du registre croisees avec ce que le
    `ContextBuilder` sait produire. Apres un tour, les etats reels (USED /
    DENIED / VIDE) remplacent les etats possibles — et un changement de
    fonction les efface, parce qu'ils ne decrivent plus le meme tour.

    ## Ce qui n'existe pas n'est pas simule

    Aucun mode FULL SHELL ou ABLATION, meme desactive : un controle mort est
    une promesse. Le mode est affiche comme un FAIT — ISOLATED — et les briques
    absentes du Nervous System sont nommees sous « non implemente ».
--}}
@php
    $sourceLabel = static function (string $name): string {
        $key = 'ai.inspector_source_label.'.str_replace('.', '_', $name);

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $name;
    };

    $isPlatformAdmin = (bool) (auth()->user()?->is_admin);

    // Ce que le JS a besoin de savoir pour re-eclairer la carte sans requete :
    // quelles sources chaque fonction declare, et lesquelles le builder sait
    // reellement produire. Aucune donnee de tour ici — la page se charge vide.
    $capabilitySources = [];
    foreach ($contextMap['capabilities'] as $id => $definition) {
        $capabilitySources[$id] = $definition['sources'];
    }
@endphp

<x-org-admin-layout :title="__('ai.inspector_title')" :organization="$organization">
    <div x-data="contextInspector({
            runUrl: @js(route('organization.admin.ai-context-inspector.run', ['organization' => $organization->slug])),
            capability: @js($capabilities[0] ?? ''),
            capabilitySources: @js($capabilitySources),
            sources: @js($contextMap['sources']),
            mapOpen: window.matchMedia('(min-width: 1024px)').matches,
        })"
        data-inspector-root
        data-inspector-mode="isolated"
        {{-- Les deux jeux de sources voyagent des le chargement : le changement
             de fonction re-eclaire la carte sans une seule requete. --}}
        data-inspector-capability-sources="{{ json_encode($capabilitySources, JSON_UNESCAPED_UNICODE) }}">

        {{-- ======================= BARRE INSTRUMENT ======================= --}}
        <header class="mb-4 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <h1 class="text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100" data-inspector-title>{{ __('ai.inspector_title') }}</h1>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.inspector_intro') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Le mode est un FAIT, pas un choix : une seule execution
                     existe aujourd'hui, et un selecteur le laisserait croire
                     autrement. --}}
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1 dark:bg-gray-900/60"
                      title="{{ __('ai.inspector_mode_help') }}" data-inspector-mode-chip>
                    <span class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('ai.inspector_mode') }}</span>
                    <span class="font-mono text-xs font-semibold text-gray-800 dark:text-gray-200">ISOLATED</span>
                </span>

                <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                      :class="{
                          'bg-gray-100 text-gray-600 ring-gray-500/20 dark:bg-gray-700/60 dark:text-gray-300 dark:ring-gray-500/30': status === 'idle',
                          'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20': status === 'running',
                          'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20': status === 'success',
                          'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20': status === 'refused' || status === 'rate_limited',
                          'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/20': status === 'error' || status === 'stopped',
                      }"
                      data-inspector-run-state :data-state="status">
                    <span class="relative flex h-1.5 w-1.5" aria-hidden="true">
                        <span class="absolute inline-flex h-full w-full rounded-full opacity-75" :class="{ 'animate-ping bg-sky-400': status === 'running' }"></span>
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full"
                              :class="{
                                  'bg-gray-400': status === 'idle',
                                  'bg-sky-500': status === 'running',
                                  'bg-emerald-500': status === 'success',
                                  'bg-amber-500': status === 'refused' || status === 'rate_limited',
                                  'bg-red-500': status === 'error' || status === 'stopped',
                              }"></span>
                    </span>
                    <span x-text="stateLabel()">{{ __('ai.inspector_state.idle') }}</span>
                </span>
            </div>
        </header>

        {{-- Rail gauche + colonne droite IMBRIQUEE, plutot qu'une grille a douze
             colonnes. Une grille mettait la carte, la question et la trace dans
             la meme rangee : la carte etant la plus haute, elle creusait sous la
             question un vide aussi haut qu'elle. Or en ISOLATED la densite reelle
             est mince (2 fonctions, 5 sources, au plus 2 lignes de registre), et
             un ecran de diagnostic qui appelle le remplissage finit rempli
             d'invention. Imbriquee, la colonne droite s'empile normalement et la
             hauteur de la carte ne creuse plus rien. --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">

            {{-- ==================== GAUCHE — CARTE DE CONTEXTE ==================== --}}
            <aside class="min-w-0 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 lg:w-80 lg:shrink-0"
                   data-inspector-context-map>
                {{-- Sur mobile la carte se replie : elle reste la premiere chose
                     lue, sans repousser la question sous la ligne de flottaison. --}}
                <button type="button" @click="mapOpen = !mapOpen"
                        class="flex w-full items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 text-left dark:border-gray-700 lg:pointer-events-none"
                        :aria-expanded="mapOpen" data-inspector-map-toggle>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.inspector_map_title') }}</span>
                        <span class="mt-0.5 block text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('ai.inspector_map_help') }}</span>
                    </span>
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-gray-400 transition dark:text-gray-500 lg:hidden"
                         :class="mapOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                </button>

                <div class="px-4 py-3 lg:block" :class="mapOpen ? 'block' : 'hidden'" data-inspector-map-body>

                    {{-- GOUVERNANCE — ce qui DICTE. Etats mesures par
                         NervousSystemMap : une autorite est verrouillee quand
                         aucune route d'ecriture n'existe pour cet Admin. --}}
                    <p class="font-mono text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ __('ai.inspector_map_governance') }}</p>
                    <ul class="mt-2 space-y-1.5 border-l border-gray-200 pl-3 dark:border-gray-700">
                        @foreach($contextMap['governance'] as $node)
                            @php
                                $canOpen = $node['admin_url'] !== null && (! $node['admin_requires_platform_admin'] || $isPlatformAdmin);
                            @endphp
                            <li class="group" data-inspector-map-node="{{ $node['key'] }}" data-inspector-map-state="{{ $node['state'] }}">
                                <div class="flex items-start justify-between gap-2">
                                    <span class="text-[13px] leading-snug text-gray-800 dark:text-gray-200">{{ __('ai.inspector_map_node.'.$node['key']) }}</span>
                                    <span class="mt-0.5 flex shrink-0 items-center gap-1.5">
                                        @if($node['state'] === 'locked')
                                            {{-- Verrouillee : elle s'applique a cet Admin et se gouverne
                                                 ailleurs. L'etat est MESURE par l'absence de route d'ecriture. --}}
                                            <svg class="h-3 w-3 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><title>{{ __('ai.inspector_component_state.locked') }}</title><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                                        @endif
                                        @if($canOpen)
                                            {{-- Un LIEN vers l'autorite canonique, jamais un controle
                                                 d'edition : cet ecran observe, il ne regle rien. --}}
                                            <a href="{{ $node['admin_url'] }}"
                                               class="text-sky-600 hover:underline dark:text-sky-400"
                                               title="{{ __('ai.inspector_map_authority_link') }} — {{ __('ai.inspector_map_node.'.$node['key']) }}"
                                               aria-label="{{ __('ai.inspector_map_authority_link') }} — {{ __('ai.inspector_map_node.'.$node['key']) }}"
                                               data-inspector-authority="{{ $node['key'] }}">↗</a>
                                        @endif
                                    </span>
                                </div>
                                @if($node['status'] !== null)
                                    <p class="mt-0.5 font-mono text-[11px] leading-snug text-gray-500 dark:text-gray-400">{{ $node['status'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    {{-- CONTEXTE & SOURCES — ce que la fonction a le droit de
                         LIRE. Reactif : le segmented control re-eclaire cette
                         liste sans aucune requete. --}}
                    <p class="mt-5 font-mono text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ __('ai.inspector_map_sources') }}</p>
                    <ul class="mt-2 space-y-1.5 border-l border-gray-200 pl-3 dark:border-gray-700">
                        @foreach($contextMap['sources'] as $source)
                            <li class="flex items-baseline justify-between gap-2"
                                data-inspector-map-source="{{ $source['key'] }}"
                                :data-inspector-map-state="sourceState(@js($source))">
                                <span class="text-[13px] leading-snug"
                                      :class="stateTextClass(sourceState(@js($source)))">{{ $sourceLabel($source['key']) }}</span>
                                <span class="flex shrink-0 items-center gap-1.5">
                                    <span class="font-mono text-[10px] uppercase tracking-wider"
                                          :class="stateTextClass(sourceState(@js($source)))"
                                          x-text="stateLabelFor(sourceState(@js($source)))">{{ __('ai.inspector_component_state.available') }}</span>
                                    <span class="h-2 w-2 rounded-full"
                                          :class="stateDotClass(sourceState(@js($source)))" aria-hidden="true"></span>
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    {{-- EXECUTION — l'etat runtime de CE mode. Le membre existe
                         (ses droits s'appliquent), la Boucle n'est pas demandee,
                         le contexte de page n'a aucune source dans ce builder. --}}
                    <p class="mt-5 font-mono text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ __('ai.inspector_map_runtime') }}</p>
                    <ul class="mt-2 space-y-1.5 border-l border-gray-200 pl-3 dark:border-gray-700">
                        @foreach($contextMap['runtime'] as $node)
                            <li class="flex items-baseline justify-between gap-2" data-inspector-map-runtime="{{ $node['key'] }}" data-inspector-map-state="{{ $node['state'] }}">
                                <span class="text-[13px] leading-snug {{ $node['state'] === 'active' ? 'text-gray-800 dark:text-gray-200' : 'text-gray-500 dark:text-gray-400' }}">{{ __('ai.inspector_map_runtime_label.'.$node['key']) }}</span>
                                <span class="flex shrink-0 items-center gap-1.5">
                                    <span class="font-mono text-[10px] uppercase tracking-wider {{ $node['state'] === 'active' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-400 dark:text-gray-500' }}">{{ __('ai.inspector_component_state.'.$node['state']) }}</span>
                                    <span class="h-2 w-2 rounded-full {{ $node['state'] === 'active' ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600' }}" aria-hidden="true"></span>
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    {{-- NON IMPLEMENTE — nommer une absence vaut mieux qu'un
                         trou. Aucune de ces briques n'existe dans le repo, et
                         aucune n'est simulee. --}}
                    <p class="mt-5 font-mono text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500"
                       title="{{ __('ai.inspector_map_deferred_help') }}">{{ __('ai.inspector_map_deferred') }}</p>
                    <ul class="mt-2 space-y-1 border-l border-dashed border-gray-200 pl-3 dark:border-gray-700">
                        @foreach($contextMap['deferred'] as $node)
                            <li class="flex items-baseline justify-between gap-2" data-inspector-map-deferred="{{ $node['key'] }}" data-inspector-map-state="unavailable">
                                <span class="text-[13px] leading-snug text-gray-400 dark:text-gray-500">{{ __('ai.inspector_map_deferred_label.'.$node['key']) }}</span>
                                <span class="font-mono text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('ai.inspector_component_state.unavailable') }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-1.5 text-[11px] leading-relaxed text-gray-400 dark:text-gray-500">{{ __('ai.inspector_map_deferred_help') }}</p>

                    <div class="mt-5 border-t border-gray-200 pt-3 dark:border-gray-700">
                        <p class="text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('ai.inspector_authorities_help') }}</p>
                        <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-[11px]">
                            <a class="text-sky-600 hover:underline dark:text-sky-400" href="{{ route('organization.admin.ai-behavior', ['organization' => $organization->slug]) }}" data-inspector-link="behavior">{{ __('ai.behavior_title') }}</a>
                            <a class="text-sky-600 hover:underline dark:text-sky-400" href="{{ route('organization.admin.ai-knowledge', ['organization' => $organization->slug]) }}" data-inspector-link="knowledge">{{ __('navigation.org_admin_ai_knowledge') }}</a>
                            <a class="text-sky-600 hover:underline dark:text-sky-400" href="{{ route('organization.admin.ai-cockpit', ['organization' => $organization->slug]) }}" data-inspector-link="cockpit">{{ __('ai.cockpit_title') }}</a>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col gap-4">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start">

            {{-- ==================== CENTRE — QUESTION / REPONSE ==================== --}}
            <section class="min-w-0 flex-1 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700" data-inspector-form-card>

                    {{-- Segmented control : deux fonctions REELLEMENT executables
                         ici. Jamais un `<select>` — l'arbitrage se voit, il ne se
                         deroule pas. --}}
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-mono text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ __('ai.inspector_capability') }}</span>
                        <div class="inline-flex rounded-lg bg-gray-100 p-0.5 dark:bg-gray-900/60" role="group" data-inspector-capability-control>
                            @foreach($capabilities as $capabilityId)
                                <button type="button"
                                        @click="selectCapability(@js($capabilityId))"
                                        class="rounded-md px-3 py-1.5 text-xs font-medium transition"
                                        :class="capability === @js($capabilityId)
                                            ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100'
                                            : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'"
                                        :aria-pressed="capability === @js($capabilityId)"
                                        data-inspector-capability-option="{{ $capabilityId }}">{{ __('ai.capability_label.'.$capabilityId) }}</button>
                            @endforeach
                        </div>
                    </div>

                    <form class="mt-3" @submit.prevent="run()" data-inspector-form>
                        <label for="inspector-question" class="sr-only">{{ __('ai.inspector_question') }}</label>
                        <textarea id="inspector-question" x-model="question" rows="3" maxlength="1000"
                                  placeholder="{{ __('ai.inspector_question_placeholder') }}"
                                  class="w-full resize-y rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm leading-relaxed text-gray-900 placeholder:text-gray-400 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-900/40 dark:text-gray-100"
                                  :class="errors.question ? 'border-red-400 dark:border-red-500' : ''"
                                  data-inspector-question></textarea>

                        <template x-if="errors.question">
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-text="errors.question" data-inspector-error="question"></p>
                        </template>
                        <template x-if="errors.capability">
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-text="errors.capability" data-inspector-error="capability"></p>
                        </template>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <p class="max-w-md text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('ai.inspector_help') }}</p>
                            <button type="submit"
                                    :disabled="status === 'running' || question.trim().length < 3"
                                    class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-gray-700 disabled:opacity-40 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
                                    data-inspector-run>
                                <svg x-show="status === 'running'" x-cloak class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                                <span x-text="status === 'running' ? @js(__('ai.inspector_running')) : @js(__('ai.inspector_run'))">{{ __('ai.inspector_run') }}</span>
                            </button>
                        </div>
                    </form>
                </div>

                {{-- Bandeau d'incident : session perdue, limite atteinte, panne.
                     Aucun retry automatique — une relance est un appel IA reel. --}}
                <template x-if="notice">
                    <div class="border-b border-amber-200 bg-amber-50 px-5 py-2.5 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
                         x-text="notice" data-inspector-notice></div>
                </template>

                <div class="px-5 py-4">
                    <div x-show="!hasRun" class="py-8 text-center" data-inspector-answer-idle>
                        <p class="text-sm text-gray-400 dark:text-gray-500">{{ __('ai.inspector_answer_idle') }}</p>
                    </div>
                    <div x-show="status === 'running'" x-cloak class="py-8" data-inspector-answer-running>
                        <div class="h-1 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full w-1/3 animate-pulse rounded-full bg-sky-500"></div>
                        </div>
                        <p class="mt-3 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('ai.inspector_running_help') }}</p>
                    </div>
                    <div x-ref="answerPane" x-show="hasRun && status !== 'running'" x-cloak data-inspector-answer-pane></div>
                </div>
            </section>

            {{-- ==================== DROITE — TRACE D'EXECUTION ==================== --}}
            <aside class="min-w-0 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 xl:w-80 xl:shrink-0"
                   data-inspector-trace>
                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.inspector_trace_title') }}</h2>
                    <p class="mt-0.5 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('ai.inspector_trace_help') }}</p>
                </div>
                <div class="px-4 py-3">
                    {{-- Squelette a vide : les quatre etapes du tour existent
                         avant lui, et disent ce qui sera observe. --}}
                    <ol x-show="!hasRun" class="space-y-0" data-inspector-trace-idle>
                        @foreach(['composition', 'context', 'provider', 'issue'] as $index => $step)
                            <li class="relative pl-6 {{ $index < 3 ? 'pb-4 before:absolute before:left-[5px] before:top-4 before:bottom-0 before:w-px before:bg-gray-200 dark:before:bg-gray-700' : '' }}">
                                <span class="absolute left-0 top-1.5 h-2.5 w-2.5 rounded-full border border-dashed border-gray-400 dark:border-gray-500" aria-hidden="true"></span>
                                <p class="font-mono text-[11px] uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('ai.inspector_trace_step.'.$step) }}</p>
                                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ __('ai.inspector_trace_pending') }}</p>
                            </li>
                        @endforeach
                    </ol>
                    <div x-ref="tracePane" x-show="hasRun" x-cloak data-inspector-trace-pane></div>
                </div>
            </aside>

            {{-- ==================== BAS — SOURCES + TELEMETRIE ==================== --}}
            </div>

            <section class="min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"
                     @click="onSourcesClick($event)">
                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.inspector_sources_title') }}</h2>
                    <p class="mt-0.5 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('ai.inspector_sources_help') }}</p>
                </div>
                <div class="px-5 py-4">
                    <p x-show="!hasRun" class="text-sm text-gray-400 dark:text-gray-500" data-inspector-sources-idle>{{ __('ai.inspector_sources_idle') }}</p>
                    <div x-ref="sourcesPane" x-show="hasRun" x-cloak data-inspector-sources-pane></div>
                </div>
                <div class="border-t border-gray-200 bg-gray-50 px-5 py-3 dark:border-gray-700 dark:bg-gray-900/40">
                    <p x-show="!hasRun" class="text-[11px] text-gray-400 dark:text-gray-500" data-inspector-telemetry-idle>{{ __('ai.inspector_telemetry_idle') }}</p>
                    <div x-ref="telemetryPane" x-show="hasRun" x-cloak data-inspector-telemetry-pane></div>
                </div>
            </section>
            </div>
        </div>

        {{-- ============================ DRAWER ============================ --}}
        {{-- Extraits REELLEMENT transmis au modele, tels que le pipeline les a
             collectes. Ils sont deja dans le DOM (rendus par le POST) : aucune
             seconde requete, donc aucun second chemin d'acces. Sources USED
             uniquement — une source refusee n'ouvre rien. --}}
        <div x-show="drawerOpen" x-cloak
             class="fixed inset-0 z-50 flex items-start justify-end bg-gray-900/40 p-4 sm:p-6"
             @click.self="drawerOpen = false" @keydown.escape.window="drawerOpen = false"
             data-inspector-drawer>
            <div class="flex h-full w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl dark:bg-gray-800">
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.inspector_provenance_title') }}</h2>
                        <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400" x-text="drawerTitle"></p>
                    </div>
                    <button type="button" @click="drawerOpen = false" class="shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="{{ __('ai.knowledge_console_close') }}">&times;</button>
                </div>
                <div class="flex-1 overflow-y-auto px-4 py-4">
                    <p class="mb-3 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('ai.inspector_provenance_help') }}</p>
                    <div x-html="drawerHtml" data-inspector-drawer-body></div>
                </div>
                <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                    <a href="{{ route('organization.admin.ai-knowledge', ['organization' => $organization->slug]) }}"
                       class="text-xs text-sky-600 hover:underline dark:text-sky-400">{{ __('ai.inspector_provenance_open_observatory') }} →</a>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('contextInspector', (config) => ({
                runUrl: config.runUrl,
                capability: config.capability,
                capabilitySources: config.capabilitySources || {},
                sources: config.sources || [],

                question: '',
                status: 'idle',
                // Replie sur mobile uniquement : au-dessus de `lg` le rail est
                // toujours visible et le bouton d'entete est inerte.
                mapOpen: config.mapOpen,
                hasRun: false,
                errors: {},
                notice: '',
                // Etats de source MESURES par le dernier tour. Vide = aucun tour
                // ne decrit la fonction actuellement selectionnee.
                projection: {},

                drawerOpen: false,
                drawerTitle: '',
                drawerHtml: '',

                labels: @js(__('ai.inspector_state')),
                componentLabels: @js(__('ai.inspector_component_state')),
                notices: {
                    stopped: @js(__('ai.inspector_error_session')),
                    rate_limited: @js(__('ai.inspector_error_rate_limited')),
                    error: @js(__('ai.inspector_error_generic')),
                },

                stateLabel() {
                    return this.labels[this.status] || this.labels.idle;
                },

                stateLabelFor(state) {
                    return this.componentLabels[state] || state;
                },

                // L'etat d'une source, dans cet ordre exact : ce que le builder
                // ne sait pas produire n'est pas « disponible mais vide » ; ce
                // que la fonction ne declare pas n'est pas « refuse » ; et un
                // etat mesure par le tour l'emporte sur un etat possible.
                sourceState(source) {
                    if (!source.implemented) return 'unavailable';
                    if (!(this.capabilitySources[this.capability] || []).includes(source.key)) return 'not_requested';
                    return this.projection[source.key] || 'available';
                },

                stateDotClass(state) {
                    return {
                        used: 'bg-emerald-500',
                        available: 'bg-gray-300 dark:bg-gray-600',
                        not_requested: 'bg-gray-200 dark:bg-gray-700',
                        empty: 'bg-gray-400 dark:bg-gray-500',
                        denied: 'bg-amber-500',
                        unavailable: 'border border-dashed border-gray-400 dark:border-gray-500',
                    }[state] || 'bg-gray-300 dark:bg-gray-600';
                },

                stateTextClass(state) {
                    if (state === 'used') return 'text-emerald-700 dark:text-emerald-300';
                    if (state === 'denied') return 'text-amber-700 dark:text-amber-400';
                    if (state === 'available') return 'text-gray-800 dark:text-gray-200';
                    return 'text-gray-400 dark:text-gray-500';
                },

                // Changer de fonction efface la projection : les etats mesures
                // decrivaient un tour qui n'est plus celui qu'on regarde.
                selectCapability(id) {
                    if (this.capability === id || this.status === 'running') return;
                    this.capability = id;
                    this.projection = {};
                    this.hasRun = false;
                    this.status = 'idle';
                    this.notice = '';
                    this.errors = {};
                    this.clearPanes();
                },

                clearPanes() {
                    ['answerPane', 'tracePane', 'sourcesPane', 'telemetryPane'].forEach((ref) => {
                        if (this.$refs[ref]) this.$refs[ref].innerHTML = '';
                    });
                },

                csrf() {
                    return document.querySelector('meta[name="csrf-token"]')?.content || '';
                },

                async run() {
                    if (this.status === 'running') return;

                    this.errors = {};
                    this.notice = '';
                    this.projection = {};
                    this.clearPanes();
                    this.status = 'running';
                    this.hasRun = false;

                    let response;

                    try {
                        response = await fetch(this.runUrl, {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': this.csrf(),
                                'Content-Type': 'application/json',
                                // JSON pour les erreurs, HTML pour le tour.
                                'Accept': 'application/json, text/html;q=0.9',
                            },
                            credentials: 'same-origin',
                            cache: 'no-store',
                            body: JSON.stringify({ capability: this.capability, question: this.question }),
                        });
                    } catch (error) {
                        // Panne reseau : aucun retry automatique — une relance
                        // est un appel IA reel, facture.
                        this.fail('error');
                        return;
                    }

                    // Rien n'a couru : l'etat de run ne bouge pas, seuls les
                    // messages de champ apparaissent.
                    if (response.status === 422) {
                        const body = await response.json().catch(() => ({}));
                        const errors = body.errors || {};
                        Object.keys(errors).forEach((field) => { this.errors[field] = errors[field][0]; });
                        this.status = 'idle';
                        return;
                    }

                    if (response.status === 419 || response.status === 401 || response.status === 403) {
                        this.fail('stopped');
                        return;
                    }

                    if (response.status === 429) {
                        this.fail('rate_limited');
                        return;
                    }

                    if (!response.ok) {
                        this.fail('error');
                        return;
                    }

                    const html = await response.text();
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const run = doc.querySelector('[data-inspector-generated-at]');

                    // Session expiree : `auth` repond par une redirection vers
                    // /login (200 apres suivi). Un fragment legitime porte
                    // toujours son horodatage serveur ; tout autre contenu
                    // n'entre jamais dans l'instrument.
                    if (response.redirected || run === null) {
                        this.fail('stopped');
                        return;
                    }

                    ['answer', 'trace', 'sources', 'telemetry'].forEach((pane) => {
                        const node = run.querySelector('[data-inspector-pane="' + pane + '"]');
                        const target = this.$refs[pane + 'Pane'];
                        if (target) target.innerHTML = node ? node.innerHTML : '';
                    });

                    try {
                        this.projection = JSON.parse(run.dataset.inspectorSourceStates || '{}');
                    } catch (error) {
                        this.projection = {};
                    }

                    this.hasRun = true;
                    this.status = run.dataset.inspectorRunState || 'success';
                },

                fail(status) {
                    this.status = status;
                    this.hasRun = false;
                    this.notice = this.notices[status] || this.notices.error;
                    this.clearPanes();
                },

                // Le tiroir ne lit QUE ce que le partiel a deja rendu : aucune
                // requete, donc aucun second chemin d'acces a la connaissance.
                onSourcesClick(event) {
                    const button = event.target.closest('[data-inspector-provenance-open]');
                    if (!button) return;

                    const key = button.getAttribute('data-inspector-provenance-open');
                    const block = this.$refs.sourcesPane?.querySelector('[data-inspector-provenance="' + key + '"]');

                    this.drawerTitle = button.getAttribute('data-inspector-provenance-label') || key;
                    this.drawerHtml = block ? block.innerHTML : '';
                    this.drawerOpen = true;
                },
            }));
        });
    </script>
    @endpush
</x-org-admin-layout>
