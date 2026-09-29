{{-- TASK-1648 — le Preview.

     « Un scenario doit etre comprehensible AVANT qu'une Organization
     n'existe » (CDC 11.1). Cet ecran lit le Manifest administratif et ne
     depend d'aucune sandbox chargee.

     Il ne mute rien : ni Validate, ni Approve, ni Load, ni edition. Ces
     actions arrivent en T1649 et au-dela.

     Deux sources, et il ne faut pas les confondre :
     - les COMPTEURS et le verdict viennent de `validation_summary`, calcule
       au save. Le Validator n'est jamais relance ici (CDC 11.1).
     - les OBJETS des onglets viennent du document, parse UNE fois pour cette
       seule version. --}}
<x-admin-layout :title="$version->name">
    <div class="mb-4">
        <a href="{{ route('admin.outils.scenarios') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ __('admin.scenario_manager.preview_back') }}</a>
    </div>

    <header class="mb-6">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $version->name }}</h1>

            @if($version->isLoaded())
                <span data-state="loaded" class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-1 text-xs font-semibold text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">{{ __('admin.scenario_manager.state_loaded') }}</span>
            @elseif($version->isValid())
                <span data-state="valid" class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-300">{{ __('admin.scenario_manager.state_valid') }}</span>
            @else
                <span data-state="draft" class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.state_draft') }}</span>
            @endif
        </div>
        <p class="mt-1 font-mono text-xs text-gray-400 dark:text-gray-500">{{ $version->scenario_key }} · v{{ $version->version }}</p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.usage_'.$version->usage) }}</p>
    </header>

    {{-- ================================================================
         TASK-1656 §23 et §24 — ce que contient le scenario, puis QUOI FAIRE.

         Avant : la fiche ouvrait sur le digest, les identifiants et les
         details techniques. L'utilisateur devait deviner le workflow. Ici,
         l'action PRINCIPALE depend de l'etat et porte un verbe, pas un nom de
         mecanisme. Les details techniques descendent plus bas, sous un
         `<details>` — accessibles, mais plus en premier.
         ================================================================ --}}
    @if($compteurs !== [])
        <section class="mb-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.fiche_contains') }}</h2>
            <p data-contains class="mt-1 text-sm text-gray-800 dark:text-gray-200">
                {{ ($compteurs['users'] ?? 0).' '.__('admin.scenario_manager.counter_users') }}
                · {{ ($compteurs['loops'] ?? 0).' '.__('admin.scenario_manager.counter_loops') }}
                · {{ ($compteurs['messages'] ?? 0).' '.__('admin.scenario_manager.counter_messages') }}
                · {{ ($compteurs['dossiers'] ?? 0).' '.__('admin.scenario_manager.counter_dossiers') }}
                · {{ ($compteurs['service_requests'] ?? 0).' '.__('admin.scenario_manager.counter_requests') }}
                · {{ ($compteurs['events'] ?? 0).' '.__('admin.scenario_manager.counter_events') }}
            </p>
        </section>
    @endif

    @php
        // `isLoaded()` dit qu'un chargement EXISTE, pas que la sandbox est
        // encore VIVANTE.
        //
        // Et la relation ne rend PAS `null` pour une sandbox en corbeille :
        // `ScenarioPackLoad::organization()` porte `->withTrashed()`, pose en
        // T1650 precisement pour que Reset et Retirer restent atteignables sur
        // une sandbox supprimee. Tester la nullite ne garde donc rien — il faut
        // tester `trashed()`, comme le fait deja la section sandbox plus bas.
        //
        // Offrir « Ouvrir la sandbox » ou « Voir en tant que persona » sur une
        // corbeille reviendrait a promettre une porte qui refusera.
        $organisationDeLaSandbox = $version->scenarioPackLoad?->organization;
        $sandboxVivante = $version->isLoaded()
            && $organisationDeLaSandbox !== null
            && ! $organisationDeLaSandbox->trashed();
    @endphp

    <section data-actions class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-wrap items-center gap-2">
            {{-- L'action PRINCIPALE, une seule, selon l'etat. --}}
            @if($sandboxVivante)
                <a href="{{ route('organization.dashboard', ['organization' => $version->scenarioPackLoad?->organization?->slug]) }}"
                   data-primary="loaded"
                   class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    {{ __('admin.scenario_manager.primary_loaded') }}
                </a>
            @elseif($version->isValid() && ! $version->isLoaded())
                <a href="{{ route('admin.outils.scenarios.approval', $version) }}"
                   data-primary="valid"
                   class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    {{ __('admin.scenario_manager.primary_valid') }}
                </a>
            @elseif(! $version->isLoaded())
                <a href="{{ route('admin.outils.scenarios.visual', $version) }}"
                   data-primary="draft"
                   class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    {{ __('admin.scenario_manager.primary_draft') }}
                </a>
            @endif

            {{-- Reste le cas d'une version chargee dont la sandbox a disparu :
                 aucune action principale n'est PROPOSEE, parce qu'aucune ne
                 s'applique. La phrase ci-dessous l'explique plutot que de
                 laisser un ecran muet. --}}

            {{-- Puis les secondaires, dans l'ordre du §24. --}}
            @if($sandboxVivante)
                <a href="{{ route('admin.outils.scenarios.personas', $version) }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('admin.scenario_manager.personas_link') }}
                </a>
                <a href="{{ route('admin.outils.scenarios.capture', $version) }}"
                   data-action="capture"
                   class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('admin.scenario_manager.card_capture') }}
                </a>
            @endif

            @if($version->isValid() && ! $version->isLoaded())
                <a href="{{ route('admin.outils.scenarios.export', $version) }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('admin.scenario_manager.editor_export') }}
                </a>
            @endif

            {{-- TASK-1656 — « Valider » n'existait QUE sur l'editeur JSON.
                 Un scenario cree depuis le modele OFSH porte deja un contenu
                 valide : exiger d'ouvrir le JSON pour le declarer tel rendait le
                 parcours du §26 impossible « sans ouvrir le JSON ». La route
                 existe depuis T1649 — elle est seulement exposee ici.

                 L'action principale du DRAFT reste « Continuer la construction »
                 (§24) : valider est un geste de SORTIE de construction, pas le
                 geste par defaut de quelqu'un qui construit. --}}
            @unless($version->isValid())
                <form method="POST" action="{{ route('admin.outils.scenarios.validate', $version) }}" class="inline">
                    @csrf
                    {{-- Dit d'ou vient le geste, pour y revenir. Un drapeau, pas
                         une URL : le controleur choisit entre deux routes
                         connues. --}}
                    <input type="hidden" name="origine" value="fiche">
                    <button type="submit" data-action="validate"
                            class="inline-flex items-center rounded-lg border border-green-400 px-3 py-2 text-sm font-medium text-green-800 hover:bg-green-50 dark:border-green-700 dark:text-green-300 dark:hover:bg-green-900/20">
                        {{ __('admin.scenario_manager.editor_validate') }}
                    </button>
                </form>
            @endunless

            <x-scenario-duplicate :version="$version" />
            <x-scenario-delete :version="$version" :versions-count="$versionsDeLaClef" />
        </div>

        @if($version->isValid() && ! $version->isLoaded())
            {{-- §13 : editer une version VALID annule sa validation. Le dire
                 AVANT le clic, pas apres. --}}
            <p class="mt-3 text-xs text-amber-800 dark:text-amber-300">{{ __('admin.scenario_manager.valid_edit_warning') }}</p>
        @elseif($version->isLoaded() && ! $sandboxVivante)
            <p data-sandbox="absente" class="mt-3 text-xs text-gray-600 dark:text-gray-400">{{ __('admin.scenario_manager.loaded_explained') }}</p>
        @endif
    </section>

    {{-- ================================================================
         TASK-1656 §20 et §21 — « a completer » n'est pas « invalide ».
         ================================================================ --}}
    @if($lecture->estACompleter())
        <section data-readiness="a_completer" class="mb-6 rounded-xl border border-blue-300 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
            <h2 class="text-sm font-semibold text-blue-900 dark:text-blue-200">{{ __('admin.scenario_manager.todo_title') }}</h2>
            <p class="mt-1 text-xs text-blue-800 dark:text-blue-300">{{ __('admin.scenario_manager.todo_hint') }}</p>

            @if($lecture->checklist() !== [])
                <ul class="mt-3 space-y-1">
                    @foreach($lecture->checklist() as $point)
                        <li data-todo="{{ $point['cle'] }}" data-present="{{ $point['present'] ? 'oui' : 'non' }}"
                            class="flex items-center gap-2 text-sm {{ $point['present'] ? 'text-blue-700 dark:text-blue-300' : 'text-blue-900 dark:text-blue-100' }}">
                            <span aria-hidden="true">{{ $point['present'] ? '✓' : '○' }}</span>
                            <span>{{ __('admin.scenario_manager.todo_item_'.$point['cle']) }}</span>
                            <span class="text-xs text-blue-600 dark:text-blue-400">
                                {{ $point['present'] ? __('admin.scenario_manager.todo_done') : __('admin.scenario_manager.todo_missing') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @elseif($lecture->estInvalide())
        <section data-readiness="invalide" class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <h2 class="text-sm font-semibold text-red-900 dark:text-red-200">{{ __('admin.scenario_manager.invalid_title') }}</h2>
            <p class="mt-1 text-xs text-red-800 dark:text-red-300">{{ __('admin.scenario_manager.invalid_hint') }}</p>
        </section>
    @endif

    {{-- Les DEUX portes d'edition, et le seul endroit de l'application qui les
         ouvre.

         Trouve en relecture adverse : `scenarios.visual` comme `scenarios.edit`
         n'etaient cites par AUCUNE vue. L'editeur JSON etait orphelin depuis
         T1649, et l'editeur visuel de T1651 heritait du meme sort — un ecran
         livre, teste, et atteignable seulement en tapant son URL a la main.
         Aucun test ne pouvait le voir : tous appellent `route()` en direct.

         Une version CHARGEE garde ses liens : les deux ecrans s'ouvrent en
         lecture et disent eux-memes pourquoi. Les retirer ici ferait croire a
         une panne. --}}
    <section class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.edit_section') }}</h2>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('admin.outils.scenarios.visual', $version) }}"
               data-open-visual
               class="flex-1 rounded-lg border border-indigo-300 p-3 hover:bg-indigo-50 dark:border-indigo-700 dark:hover:bg-indigo-900/20">
                <span class="block text-sm font-semibold text-indigo-800 dark:text-indigo-300">{{ __('admin.scenario_manager.visual_open') }}</span>
                <span class="mt-1 block text-xs text-gray-600 dark:text-gray-400">{{ __('admin.scenario_manager.visual_open_hint') }}</span>
            </a>

            <a href="{{ route('admin.outils.scenarios.edit', $version) }}"
               data-open-json
               class="flex-1 rounded-lg border border-gray-300 p-3 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">
                <span class="block text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('admin.scenario_manager.editor_open') }}</span>
                <span class="mt-1 block text-xs text-gray-600 dark:text-gray-400">{{ __('admin.scenario_manager.editor_open_hint') }}</span>
            </a>
        </div>
    </section>

    @unless($preview->isReadable())
        <div class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <p class="text-sm font-semibold text-red-900 dark:text-red-200">{{ __('admin.scenario_manager.preview_unreadable') }}</p>
            @if($onglet === 'resume')
                <p class="mt-1 text-xs text-red-800 dark:text-red-300">{{ __('admin.scenario_manager.preview_unreadable_hint') }}</p>
            @endif
        </div>
    @endunless

    {{-- Vue d'ensemble (CDC 11.2), retrogradee par TASK-1656 §23.

         Le CDC exige ces informations : elles restent donc TOUTES la, digest
         compris, et la mention du §11.2 sur la nouvelle sandbox aussi. Mais
         elles ne sont plus la PREMIERE chose que l'ecran dit. Un `<details>`
         plutot qu'un panneau Alpine : le contenu reste atteignable sans script,
         et il est dans le DOM pour qui le cherche. --}}
    <details data-technical class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('admin.scenario_manager.technical_details') }}
        </summary>

        <dl class="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
            @php
                $lignes = [
                    __('admin.scenario_manager.preview_id') => $preview->header('id'),
                    __('admin.scenario_manager.col_version') => $version->version,
                    __('admin.scenario_manager.preview_document_version') => $preview->header('version'),
                    __('admin.scenario_manager.preview_locale') => $preview->header('locale'),
                    __('admin.scenario_manager.col_usage') => __('admin.scenario_manager.usage_'.$version->usage),
                    __('admin.scenario_manager.col_origin') => __('admin.scenario_manager.origin_'.$version->origin),
                    __('admin.scenario_manager.preview_proposed_slug') => $preview->proposedSlug(),
                ];
            @endphp
            @foreach($lignes as $etiquette => $valeur)
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $etiquette }}</dt>
                    <dd class="font-mono text-sm text-gray-900 dark:text-gray-100">{{ $valeur ?: '—' }}</dd>
                </div>
            @endforeach

            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_digest') }}</dt>
                <dd class="break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ $version->digest ?: __('admin.scenario_manager.no_digest') }}</dd>
            </div>

            @if($preview->header('purpose'))
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_purpose') }}</dt>
                    <dd class="text-sm text-gray-700 dark:text-gray-300">{{ $preview->header('purpose') }}</dd>
                </div>
            @endif
        </dl>

        {{-- Mention EXPLICITE exigee par le CDC 11.2 : un chargement ne cible
             jamais une Organization existante. --}}
        <p class="mt-4 rounded-lg bg-blue-50 p-3 text-xs text-blue-900 dark:bg-blue-900/20 dark:text-blue-200">
            {{ __('admin.scenario_manager.preview_new_sandbox') }}
        </p>
    </details>

    @if(session('status'))
        <div class="mb-6 rounded-xl border border-green-300 bg-green-50 p-4 text-sm text-green-900 dark:border-green-800 dark:bg-green-900/20 dark:text-green-200">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <ul class="space-y-1 text-sm text-red-900 dark:text-red-200">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Apres Load (CDC 13.5) : l'Organization creee, son slug REEL — qui peut
         differer du slug propose, et ce n'est pas un incident —, la date, le
         digest charge, puis les gestes qui s'appliquent a une sandbox vivante.

         Reinitialiser et Retirer vivent ICI et nulle part ailleurs : ce sont
         des gestes sur un MONDE, pas sur une definition. Supprimer la version
         reste sur l'editeur, et le CDC 14.3 insiste : ce sont deux gestes
         distincts. --}}
    @if($version->isLoaded() && $version->scenarioPackLoad?->organization)
        @php $sandbox = $version->scenarioPackLoad->organization; @endphp

        <section class="mb-6 rounded-xl border border-indigo-300 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-indigo-800 dark:text-indigo-300">{{ __('admin.scenario_manager.sandbox_title') }}</h2>

            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-indigo-700 dark:text-indigo-400">{{ __('admin.scenario_manager.col_scenario') }}</dt>
                    <dd data-sandbox-name class="text-sm text-indigo-900 dark:text-indigo-200">{{ $sandbox->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-indigo-700 dark:text-indigo-400">{{ __('admin.scenario_manager.sandbox_slug') }}</dt>
                    <dd data-sandbox-slug class="font-mono text-sm text-indigo-900 dark:text-indigo-200">{{ $sandbox->slug }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-indigo-700 dark:text-indigo-400">{{ __('admin.scenario_manager.sandbox_loaded_at') }}</dt>
                    <dd data-sandbox-loaded-at class="text-sm text-indigo-900 dark:text-indigo-200">{{ $version->scenarioPackLoad->loaded_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                @if($version->scenarioPackLoad->reset_at)
                    <div>
                        <dt class="text-xs text-indigo-700 dark:text-indigo-400">{{ __('admin.scenario_manager.sandbox_reset_at') }}</dt>
                        <dd class="text-sm text-indigo-900 dark:text-indigo-200">{{ $version->scenarioPackLoad->reset_at->format('d/m/Y H:i') }}</dd>
                    </div>
                @endif

                <div class="sm:col-span-3">
                    <dt class="text-xs text-indigo-700 dark:text-indigo-400">{{ __('admin.scenario_manager.sandbox_digest') }}</dt>
                    <dd data-sandbox-digest class="break-all font-mono text-xs text-indigo-800 dark:text-indigo-300">{{ $version->scenarioPackLoad->manifest_digest ?? '—' }}</dd>
                </div>
            </dl>

            <div class="mt-4 flex flex-wrap items-start gap-3">
                {{-- CDC 13.5 : « Apres Load, afficher : Organization creee ;
                     slug reel ; date ; digest charge ; compteurs ; Open
                     sandbox ; Reset ; Capture ; Remove. »

                     Trouve en revue : la cle de langue `sandbox_open` existait
                     dans les deux locales et n'etait referencee NULLE PART.
                     Une exigence a moitie livree — le mot traduit, le geste
                     absent — est plus trompeuse qu'une exigence oubliee.

                     Une sandbox en CORBEILLE ne s'ouvre pas : la liaison de
                     route la refuserait. On dit alors ce qui est, plutot que
                     de tendre un lien mort. Reset et Remove, eux, restent
                     disponibles : c'est precisement l'etat ou il faut pouvoir
                     agir. --}}
                @if($sandbox->trashed())
                    <p data-sandbox-trashed class="rounded-lg border border-amber-400 px-3 py-2 text-sm text-amber-800 dark:border-amber-700 dark:text-amber-300">
                        {{ __('admin.scenario_manager.sandbox_trashed') }}
                    </p>
                @else
                    <a href="{{ route('admin.organizations.edit', $sandbox) }}"
                       data-sandbox-open
                       class="rounded-lg border border-indigo-400 px-3 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100 dark:border-indigo-600 dark:text-indigo-300 dark:hover:bg-indigo-900/40">
                        {{ __('admin.scenario_manager.sandbox_open') }}
                    </a>
                @endif

                {{-- TASK-1653 — capturer l'etat actuel.

                     Place AVANT Reinitialiser, et ce n'est pas un detail :
                     Reset EFFACE ce qui a ete fait dans la sandbox. Proposer
                     d'abord de le CONSERVER, c'est mettre le geste
                     constructif devant le geste destructeur.

                     Le lien n'ouvre pas une action : il ouvre un ecran de
                     comparaison. Rien n'est cree tant que l'operateur n'a pas
                     lu ce qui a change. --}}
                {{-- TASK-1654 — l'entree du mode persona.

                     Elle est ici, et pas dans un menu general : entrer dans un
                     monde n'a de sens que depuis la version qui l'a cree. Elle
                     est aussi CONDITIONNEE a une sandbox vivante — une sandbox
                     en corbeille n'accueille personne, et proposer le geste
                     reviendrait a promettre une porte qui refusera. --}}
                @unless($sandbox->trashed())
                    <a href="{{ route('admin.outils.scenarios.personas', $version) }}"
                       data-personas-open
                       class="rounded-lg border border-indigo-500 px-3 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/30">
                        {{ __('admin.scenario_manager.personas_link') }}
                    </a>
                @endunless

                <a href="{{ route('admin.outils.scenarios.capture', $version) }}"
                   data-capture-open
                   class="rounded-lg border border-green-500 px-3 py-2 text-sm font-semibold text-green-800 hover:bg-green-50 dark:border-green-700 dark:text-green-300 dark:hover:bg-green-900/30">
                    {{ __('admin.scenario_manager.capture_action') }}
                </a>

                {{-- Reinitialiser rejoue le monde : c'est un geste qui EFFACE
                     ce qu'on a fait dans la sandbox. Il avait moins de
                     protection que Retirer, alors qu'il s'emploie bien plus
                     souvent — la protection etait a l'envers de l'effet. --}}
                <details class="rounded-lg border border-indigo-400 p-3 dark:border-indigo-600">
                    <summary class="cursor-pointer text-sm font-semibold text-indigo-800 dark:text-indigo-300">{{ __('admin.scenario_manager.sandbox_reset') }}</summary>
                    <p class="mt-2 max-w-xs text-xs text-indigo-700 dark:text-indigo-400">{{ __('admin.scenario_manager.sandbox_reset_hint') }}</p>
                    <form method="POST" action="{{ route('admin.outils.scenarios.reset', $version) }}" class="mt-2">
                        @csrf
                        <button type="submit" class="rounded-lg bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                            {{ __('admin.scenario_manager.sandbox_reset_confirm') }}
                        </button>
                    </form>
                </details>

                <details class="rounded-lg border border-red-300 p-3 dark:border-red-800">
                    <summary class="cursor-pointer text-sm font-semibold text-red-700 dark:text-red-400">{{ __('admin.scenario_manager.sandbox_remove') }}</summary>
                    <p class="mt-2 max-w-xs text-xs text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.sandbox_remove_hint') }}</p>
                    <form method="POST" action="{{ route('admin.outils.scenarios.remove', $version) }}" class="mt-2">
                        @csrf
                        <button type="submit" class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">
                            {{ __('admin.scenario_manager.sandbox_remove') }}
                        </button>
                    </form>
                </details>
            </div>
        </section>
    @elseif($version->isValid())
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <a href="{{ route('admin.outils.scenarios.approval', $version) }}"
               class="inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                {{ __('admin.scenario_manager.approval_title') }}
            </a>
        </div>
    @endif

    {{-- Onglets (CDC 11.3). Des LIENS, pas du JavaScript : chaque onglet a son
         URL, donc se partage et se recharge. --}}
    <nav class="mb-4 flex flex-wrap gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
        @foreach($onglets as $cle => $onglet_declare)
            <a href="{{ route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => $cle]) }}"
               @class([
                   'rounded-lg px-3 py-2 text-sm',
                   'bg-indigo-600 text-white font-semibold' => $onglet === $cle,
                   'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $onglet !== $cle,
               ])
               @if($onglet === $cle) aria-current="page" @endif>
                {{ __('admin.scenario_manager.'.$onglet_declare['libelle']) }}
            </a>
        @endforeach
    </nav>

    @if($onglet === 'resume')
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_counters') }}</h2>
            <p class="mb-4 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.preview_counters_source') }}</p>

            @if($compteurs === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_no_counters') }}</p>
            @else
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
                    @foreach($compteurs as $nom => $valeur)
                        @continue(! is_scalar($valeur))
                        <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $nom }}</dt>
                            <dd class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $valeur }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            {{-- Le verdict et l'etat disent deux choses differentes : un
                 brouillon peut avoir ete valide puis re-edite. Les montrer tous
                 les deux evite de lire l'un pour l'autre. --}}
            <p class="mt-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('admin.scenario_manager.preview_verdict') }} :
                <span class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ is_scalar($verdict) ? $verdict : __('admin.scenario_manager.preview_verdict_none') }}</span>
            </p>

            <h2 class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_errors') }}</h2>
            @if($erreurs === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_no_errors') }}</p>
            @else
                {{-- CDC 11.5 : le JSON Pointer existe pour la precision
                     technique, mais il vient APRES le message lisible. --}}
                <ul class="space-y-3">
                    @foreach(array_slice($erreurs, 0, $limite) as $erreur)
                        <li class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
                            <p class="text-sm text-red-900 dark:text-red-200">{{ $erreur['message'] ?? '' }}</p>
                            <p class="mt-1 font-mono text-xs text-red-700 dark:text-red-400">{{ $erreur['path'] ?? '' }} · {{ $erreur['code'] ?? '' }}</p>
                        </li>
                    @endforeach
                </ul>

                @if(count($erreurs) > $limite)
                    {{-- Le CDC 11.5 fait de cet ecran l'endroit ou l'on
                         comprend pourquoi un brouillon est invalide : couper
                         cinquante erreurs sans le dire y ferait perdre
                         exactement ce qu'on vient y chercher. --}}
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('admin.scenario_manager.preview_truncated', ['limit' => $limite, 'total' => count($erreurs)]) }}
                    </p>
                @endif
            @endif
        </section>
    @elseif($onglet === 'json')
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="mb-3 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.preview_json_hint') }}</p>
            {{-- Le document n'est ECRIT DANS LA PAGE que sur cet onglet : a
                 2 MiB autorises, le verser dans les huit autres couterait cher
                 pour rien. Il n'est pas « charge paresseusement » pour autant —
                 la colonne est lue par le route-binding quel que soit
                 l'onglet ; `<details>` ne fait que replier un contenu deja
                 transmis. --}}
            <details>
                <summary class="cursor-pointer text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('admin.scenario_manager.preview_show_json') }}</summary>
                <pre class="mt-3 max-h-[32rem] overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-200">{{ $version->json_source }}</pre>
            </details>
        </section>
    @else
        @foreach($familles as $famille => $colonnes)
            @php
                $lignes = $preview->family($famille);
                $total = count($lignes);
                // `training.modules` se lit « modules » : le prefixe dit ou la
                // famille vit dans le document, pas comment on la nomme.
                $titre = \Illuminate\Support\Str::afterLast($famille, '.');
            @endphp

            <section class="mb-4 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <h2 class="border-b border-gray-100 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    {{ $titre }} <span class="ml-1 font-normal normal-case text-gray-400">({{ $total }})</span>
                </h2>

                @if($total === 0)
                    <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_empty_tab') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    @foreach($colonnes as $colonne)
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $colonne }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                {{-- Borne dure : un manifeste peut declarer des
                                     centaines d'objets par famille, et rendre
                                     tout d'un coup ferait une page illisible
                                     autant que lente. --}}
                                @foreach(array_slice($lignes, 0, $limite) as $ligne)
                                    <tr>
                                        @foreach($colonnes as $colonne)
                                            @php $valeur = $ligne->{$colonne} ?? null; @endphp
                                            <td class="px-3 py-2 align-top text-gray-700 dark:text-gray-300">
                                                {{ is_scalar($valeur) ? Str::limit((string) $valeur, 90, '…') : '—' }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($total > $limite)
                        <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            {{ __('admin.scenario_manager.preview_truncated', ['limit' => $limite, 'total' => $total]) }}
                        </p>
                    @endif
                @endif
            </section>
        @endforeach
    @endif
</x-admin-layout>
