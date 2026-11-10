<x-page :title="__('loops.index_title')" :heading="__('loops.index_title')">
    @php
        $organizationRouteParam = request()->route('organization');
        $loopsCreateHref = $organizationRouteParam && request()->routeIs('organization.*')
            ? route('organization.loops.create', ['organization' => $organizationRouteParam])
            : route('loops.create');
        // TASK-1801 correctif 05 — « Derniers messages » vit sur sa PROPRE
        // page (demande de Cyril : « une page c'est mieux qu'un pop-up »).
        $latestMessagesHref = $organizationRouteParam && request()->routeIs('organization.*')
            ? route('organization.loops.latest-messages', ['organization' => $organizationRouteParam])
            : route('loops.latest-messages');
        $loopShowHref = function ($loop) use ($organizationRouteParam) {
            if ($organizationRouteParam && request()->routeIs('organization.*')) {
                // TASK-1717 — le SLUG dans l'URL org-scopee, l'identifiant en
                // repli. Meme idiome que `dossiers/espaces`, `dossiers/show` et
                // `members/index`, qui l'emploient deja : la convention
                // existait, elle n'etait simplement pas appliquee ici.
                //
                // Seulement sur la branche ORG-SCOPEE : `Route::bind('loop')`
                // refuse un slug quand la route ne porte pas d'`{organization}`,
                // donc la forme nue ci-dessous garde le modele.
                return route('organization.loops.show', ['organization' => $organizationRouteParam, 'loop' => $loop->slug ?: $loop->getKey()]);
            }

            return route('loops.show', $loop);
        };
        $loopJoinAction = function ($loop) use ($organizationRouteParam) {
            if ($organizationRouteParam && request()->routeIs('organization.*')) {
                return route('organization.loops.join', ['organization' => $organizationRouteParam, 'loop' => $loop]);
            }

            return route('loops.join', $loop);
        };
        $loopEditHref = function ($loop) use ($organizationRouteParam) {
            if ($organizationRouteParam && request()->routeIs('organization.*')) {
                return route('organization.loops.edit', ['organization' => $organizationRouteParam, 'loop' => $loop]);
            }

            return route('loops.edit', $loop);
        };
        // Set by LoopController::update() so the freshly edited card is easy to spot.
        $highlightedLoopId = request()->query('updated');
        $mine = $loops->where('is_member', true)->values();
        $discover = $loops->where('is_member', false)->values();

        // Onglets par type, sur le modèle d'Explorer mais filtrés côté client :
        // la page filtre déjà la recherche et les domaines sans aller-retour
        // serveur, et un onglet qui recharge la page perdrait ces deux réglages.
        $typeRegistry = app(\App\Support\Loops\LoopTypeRegistry::class);
        $countByType = $loops->countBy(fn ($l) => $typeRegistry->resolve($l->type));

        // La portée : les types se nomment et s'offrent **chez cette
        // Organization**. Sans elle, un type qu'elle a créé n'avait pas
        // d'onglet, et un type qu'elle a renommé s'affichait sous son nom
        // commun.
        $tabOrganization = $organization ?? null;

        // TASK-1788 correctif 06 — SEULS les types qui ont au moins une Boucle.
        //
        // La règle était « tous les types proposables, PLUS ceux qu'une Boucle
        // porte encore alors qu'ils ont été retirés de l'offre ». Elle rendait
        // donc des onglets à zéro — « Coaching 0 » — qui ne mènent nulle part :
        // les cliquer n'affiche rien, et ils poussent hors de l'écran les
        // onglets qui, eux, ont quelque chose à montrer.
        //
        // Compter suffit, et conserve la seconde moitié de l'ancienne règle
        // sans l'écrire : un type retiré de l'offre mais encore porté par une
        // Boucle a un compte non nul, donc il garde son onglet. Sans quoi ces
        // Boucles seraient visibles dans « Toutes » et introuvables ailleurs.
        //
        // `$loops` n'est jamais vide ici (ce bloc vit dans le `@else` de
        // `@if($loops->isEmpty())`) : au moins un type reste donc affiché, et
        // l'onglet « Toutes » ne se retrouve jamais seul.
        $tabTypes = collect($typeRegistry->all($tabOrganization))
            ->filter(fn ($d, $key) => ($countByType[$key] ?? 0) > 0);

        $mineByType = $mine->countBy(fn ($l) => $typeRegistry->resolve($l->type));
        $discoverByType = $discover->countBy(fn ($l) => $typeRegistry->resolve($l->type));
    @endphp

    <x-slot name="headingActions">
        {{-- Le parent (`x-page`) restreint deja cette rangee au bureau
             (`hidden md:flex`) : pas besoin de le repeter ici. --}}
        <div class="flex items-center gap-3">
            {{-- TASK-1801 correctif 05 — a la GAUCHE du bouton « Nouvelle »,
                 toujours visible : sa page montre ou non quelque chose selon
                 le role (voir `loops/latest-messages.blade.php`), pas la
                 capacite a creer. --}}
            <a href="{{ $latestMessagesHref }}"
               data-loops-latest-messages-trigger
               class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:border-indigo-400 hover:text-indigo-600 rounded-lg transition whitespace-nowrap dark:text-gray-200 dark:bg-gray-800 dark:border-gray-600 dark:hover:text-indigo-400">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>
                </svg>
                <span>{{ __('loops.latest_messages_button') }}</span>
            </a>
            @if($canCreate)
                <a href="{{ $loopsCreateHref }}"
                   class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>{{ __('loops.new') }}</span>
                </a>
            @endif
        </div>
    </x-slot>

    {{-- `mine` : TASK-1788 correctif 06. Un troisieme choix du meme selecteur,
         pas une seconde dimension — « Mes boucles », « Toutes » et un type
         s'excluent, et une seule rangee se lit d'un coup d'oeil. --}}
    <div x-data="{ q: '', domain: '', type: '', mine: false }">
        {{-- TASK-1788 correctif 06 — « Vos espaces de collaboration » est RETIREE,
             sur demande de Cyril : elle n'apprend rien que le titre de la page
             ne dise deja, et elle coute une ligne en haut d'ecran.

             Le conteneur ne subsiste que pour le bouton de creation du
             smartphone (le bouton de bureau vit dans `headingActions`). Il
             passe donc en `md:hidden` : sans cela, le bureau garderait un bloc
             vide et sa marge — la place qu'on vient justement de reprendre. La
             cle `loops.collaboration_spaces` reste definie, ce correctif etant
             un changement d'ecran et non un menage de lexique. --}}
        {{-- TASK-1801 correctif 05 — « Derniers messages » rejoint « Nouvelle »
             ici, a sa GAUCHE : le bloc ne depend donc plus de `$canCreate`
             seul, il reste rendu pour qui ne peut que lire. --}}
        <div class="md:hidden flex items-center justify-end gap-3 mb-6">
            <a href="{{ $latestMessagesHref }}"
               data-loops-latest-messages-trigger-mobile
               class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:border-indigo-400 hover:text-indigo-600 rounded-lg transition whitespace-nowrap dark:text-gray-200 dark:bg-gray-800 dark:border-gray-600 dark:hover:text-indigo-400">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>
                </svg>
                <span>{{ __('loops.latest_messages_button') }}</span>
            </a>
            @if($canCreate)
                <a href="{{ $loopsCreateHref }}"
                   data-loops-new-mobile
                   class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>{{ __('loops.new') }}</span>
                </a>
            @endif
        </div>

        @if(session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('info'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 px-4 py-3 rounded-lg text-sm">
                {{ session('info') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
                {{ session('error') }}
            </div>
        @endif

        @if(isset($noPrimaryLoopWarning) && $noPrimaryLoopWarning)
            <div class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300 px-4 py-3 rounded-lg text-sm">
                <strong>{{ __('loops.default_missing_title') }}</strong> {{ __('loops.default_missing_body') }}
            </div>
        @endif

        @if($loops->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 py-16 px-6 text-center">
                <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <p class="text-gray-400 dark:text-gray-500 mb-4">{{ __('loops.empty') }}</p>
                @if($canCreate)
                    <a href="{{ $loopsCreateHref }}"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ __('loops.create_first') }}
                    </a>
                @endif
            </div>
        @else
            {{-- Onglets de types. Même grammaire visuelle qu'Explorer :
                 soulignement indigo pour l'onglet actif, rien d'autre. --}}
            @if($tabTypes->isNotEmpty())
                <div class="mb-6 -mx-4 overflow-x-auto border-b border-gray-200 px-4 no-scrollbar dark:border-gray-700">
                    <div class="flex min-w-max">
                        {{-- TASK-1788 correctif 06 — « Mes boucles », et SEULEMENT
                             pour qui en a.

                             La condition est `$mine`, c'est-a-dire `is_member`
                             : une appartenance ACTIVE, quel que soit le role.
                             Proprietaire et animateur sont des roles de
                             `loop_members`, ils y sont donc compris — la
                             demande (« appartient, ou est proprietaire ou
                             animateur ») est couverte par un seul critere, sans
                             en recopier trois qui divergeraient. --}}
                        @if($mine->isNotEmpty())
                            <button type="button" @click="mine = true; type = ''"
                                    data-loops-tab-mine
                                    :class="mine ? 'border-b-2 border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                    class="px-5 py-3 text-sm font-medium whitespace-nowrap transition">
                                {{ __('loops.my_loops_title') }}
                                <span class="ml-1 text-xs font-normal opacity-70">{{ $mine->count() }}</span>
                            </button>
                        @endif
                        <button type="button" @click="mine = false; type = ''"
                                :class="! mine && type === '' ? 'border-b-2 border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                class="px-5 py-3 text-sm font-medium transition">
                            {{ __('loops.types_tab_all') }}
                            <span class="ml-1 text-xs font-normal opacity-70">{{ $loops->count() }}</span>
                        </button>
                        @foreach($tabTypes as $key => $definition)
                            <button type="button" @click="mine = false; type = '{{ $key }}'"
                                    :class="! mine && type === '{{ $key }}' ? 'border-b-2 border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                    class="px-5 py-3 text-sm font-medium whitespace-nowrap transition">
                                {{-- Le registre, jamais la définition : un type
                                     créé n'a pas de clé de traduction, et le mot
                                     surchargé par l'Organization doit se lire
                                     ici comme partout ailleurs. --}}
                                {{ $typeRegistry->label($key, $tabOrganization) }}
                                <span class="ml-1 text-xs font-normal opacity-70">{{ $countByType[$key] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <style>.no-scrollbar::-webkit-scrollbar { display: none; }</style>

            @php $catalogDomains = $filterDomains ?? collect(); @endphp
            @if($loops->count() > 6 || $catalogDomains->isNotEmpty())
                <div class="mb-6 flex flex-wrap items-center gap-3">
                    @if($loops->count() > 6)
                        <div class="relative w-full max-w-sm">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                            </svg>
                            <input type="search" x-model="q" placeholder="{{ __('loops.search_placeholder') }}"
                                   class="w-full rounded-xl border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                        </div>
                    @endif

                    @if($catalogDomains->isNotEmpty())
                        {{-- Client-side filter: an Organization holds ~200 people and a
                             handful of Loops, so no extra server round-trip is needed. --}}
                        <label class="sr-only" for="domain-filter">{{ __('loops.filter_by_domain') }}</label>
                        <select id="domain-filter" x-model="domain"
                                class="rounded-xl border border-gray-300 bg-white py-2 pl-3 pr-8 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">{{ __('loops.filter_all_domains') }}</option>
                            @foreach($catalogDomains as $domain)
                                <option value="{{ $domain->id }}">{{ $domain->displayName('loops') }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            @endif

            @if($mine->isNotEmpty())
                <h2 x-show="mine || type === '' || ({{ \Illuminate\Support\Js::from($mineByType) }}[type] ?? 0) > 0"
                    class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('loops.my_loops_title') }}</h2>
                <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($mine as $item)
                        @include('loops.partials.catalog-card', ['item' => $item])
                    @endforeach
                </div>
            @endif

            {{-- Un onglet vide ne doit pas être une page blanche. --}}
            <div x-show="! mine && type !== '' && ({{ \Illuminate\Support\Js::from($countByType) }}[type] ?? 0) === 0"
                 x-cloak
                 class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center dark:border-gray-600 dark:bg-gray-800">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('loops.types_tab_empty') }}</p>
                @if($canCreate)
                    <a href="{{ $loopsCreateHref }}"
                       class="mt-4 inline-flex min-h-[44px] items-center gap-1.5 rounded-xl bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ __('loops.new') }}
                    </a>
                @endif
            </div>

            {{-- TASK-1788 correctif 06 — « Decouvrir » tout entier derriere `mine`,
                 EN-TETE ET GRILLE.

                 Le piege : les cartes se filtrent elles-memes sur `q`, `domain`
                 et `type` — jamais sur `mine`, qu'elles ne connaissent pas. Ne
                 masquer que le titre aurait laisse la grille complete en
                 dessous, et « Mes boucles » n'aurait rien filtre du tout. --}}
            @if($discover->isNotEmpty())
                <div x-show="! mine" data-loops-discover>
                    <h2 x-show="type === '' || ({{ \Illuminate\Support\Js::from($discoverByType) }}[type] ?? 0) > 0"
                        class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('loops.discover_title') }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($discover as $item)
                            @include('loops.partials.catalog-card', ['item' => $item])
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        @if(($archivedLoops ?? collect())->isNotEmpty())
            {{-- Les archives, repliees par defaut. Une seconde liste et non des
                 lignes grisees dans le catalogue : une Boucle archivee n'est pas
                 une Boucle active en moins bien, c'est autre chose. --}}
            <details class="mt-10 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <summary class="cursor-pointer list-none text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('loops.archived_filter') }} ({{ $archivedLoops->count() }})
                </summary>
                <ul class="mt-3 space-y-2">
                    @foreach($archivedLoops as $archived)
                        <li class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 dark:border-gray-700">
                            <a href="{{ $archived->workspaceUrl() }}"
                               class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 hover:underline dark:text-gray-100">
                                {{ $archived->name }}
                            </a>
                            <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                {{ __('loops.archive_badge') }}
                            </span>
                            @if($archived->archived_at)
                                <span class="shrink-0 text-[11px] text-gray-400 dark:text-gray-500">
                                    {{ __('loops.archived_since', ['date' => $archived->archived_at->isoFormat('LL')]) }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>

</x-page>
