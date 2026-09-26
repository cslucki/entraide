{{-- TASK-1646 puis TASK-1648 — la bibliotheque.

     Le CDC 6.1 demande « une bibliotheque et non un simple selecteur
     technique » : des cartes, pas un tableau. Chaque carte porte ce que le
     CDC 6.2 exige — nom, version, etat, usage, date de modification,
     principaux compteurs, presence ou non d'une sandbox, action principale.

     Les boutons « Nouveau », « Importer », « Dupliquer », « Exporter » et
     « Capturer » que le CDC montre appartiennent a T1649 et au-dela. Ils sont
     OMIS plutot qu'affiches inertes : un bouton qui ne fait rien ment sur ce
     que l'ecran sait faire.

     La colonne « Etat » affiche trois valeurs alors que la base n'en stocke
     que DEUX : « Charge » est derive par `isLoaded()`, jamais lu.

     Les compteurs viennent de `validation_summary`, jamais d'une validation
     refaite au rendu (CDC 11.1) : cent cartes ne doivent pas couter cent
     validations. --}}
<x-admin-layout :title="__('admin.scenario_manager.title')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.subtitle') }}</p>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.read_only') }}</p>
    </div>

    <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
        <p class="text-sm text-amber-900 dark:text-amber-200">{{ __('admin.scenario_manager.foundation_notice') }}</p>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
        @php
            $tiles = [
                ['label' => __('admin.scenario_manager.total_scenarios'), 'value' => $totals['scenarios'], 'tone' => 'text-gray-900 dark:text-gray-100'],
                ['label' => __('admin.scenario_manager.total_versions'), 'value' => $totals['all'], 'tone' => 'text-gray-900 dark:text-gray-100'],
                ['label' => __('admin.scenario_manager.state_draft'), 'value' => $totals['draft'], 'tone' => 'text-gray-600 dark:text-gray-300'],
                ['label' => __('admin.scenario_manager.state_valid'), 'value' => $totals['valid'], 'tone' => 'text-green-700 dark:text-green-400'],
                ['label' => __('admin.scenario_manager.state_loaded'), 'value' => $totals['loaded'], 'tone' => 'text-indigo-700 dark:text-indigo-400'],
            ];
        @endphp
        @foreach($tiles as $tile)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Filtres du CDC 6.3, en GET : un filtre est une LECTURE. Il doit
         pouvoir se partager par URL et se rejouer depuis l'historique, ce
         qu'un POST interdirait. --}}
    <form method="GET" action="{{ route('admin.outils.scenarios') }}"
          class="mb-6 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800 sm:grid-cols-5">
        <div class="sm:col-span-2">
            <label for="q" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_scenario') }}</label>
            <input id="q" type="search" name="q" value="{{ $filtres['q'] }}"
                   placeholder="{{ __('admin.scenario_manager.search_placeholder') }}"
                   class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
        </div>

        <div>
            <label for="etat" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.filter_state') }}</label>
            <select id="etat" name="etat" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">{{ __('admin.scenario_manager.filter_all') }}</option>
                @foreach(\App\Models\ScenarioManifestVersion::STATES as $etatPossible)
                    <option value="{{ $etatPossible }}" @selected($filtres['etat'] === $etatPossible)>{{ __('admin.scenario_manager.state_'.$etatPossible) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="usage" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.filter_usage') }}</label>
            <select id="usage" name="usage" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">{{ __('admin.scenario_manager.filter_all') }}</option>
                @foreach(\App\Models\ScenarioManifestVersion::USAGES as $usagePossible)
                    <option value="{{ $usagePossible }}" @selected($filtres['usage'] === $usagePossible)>{{ __('admin.scenario_manager.usage_'.$usagePossible) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="charge" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.filter_loaded') }}</label>
            <select id="charge" name="charge" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">{{ __('admin.scenario_manager.filter_all') }}</option>
                <option value="oui" @selected($filtres['charge'] === 'oui')>{{ __('admin.scenario_manager.filter_loaded_yes') }}</option>
                <option value="non" @selected($filtres['charge'] === 'non')>{{ __('admin.scenario_manager.filter_loaded_no') }}</option>
            </select>
        </div>

        <div class="flex items-end gap-2 sm:col-span-5">
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('admin.scenario_manager.filter_apply') }}</button>
            @if($actif)
                <a href="{{ route('admin.outils.scenarios') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">{{ __('admin.scenario_manager.filter_reset') }}</a>
            @endif
        </div>
    </form>

    @if($versions->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $actif ? __('admin.scenario_manager.no_result') : __('admin.scenario_manager.empty') }}</p>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $actif ? __('admin.scenario_manager.no_result_hint') : __('admin.scenario_manager.empty_hint') }}</p>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($versions as $version)
                @php $compteurs = is_array($version->validation_summary['counters'] ?? null) ? $version->validation_summary['counters'] : []; @endphp

                <article class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <header class="mb-3">
                        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ $version->name }}</h2>
                        <p class="font-mono text-xs text-gray-400 dark:text-gray-500">{{ $version->scenario_key }}</p>
                    </header>

                    <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
                        @if($version->isLoaded())
                            <span data-state="loaded" class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-1 font-semibold text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">{{ __('admin.scenario_manager.state_loaded') }}</span>
                        @elseif($version->isValid())
                            <span data-state="valid" class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-300">{{ __('admin.scenario_manager.state_valid') }}</span>
                        @else
                            <span data-state="draft" class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.state_draft') }}</span>
                        @endif

                        <span class="font-mono text-gray-600 dark:text-gray-400">v{{ $version->version }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.usage_'.$version->usage) }}</span>
                    </div>

                    @if($compteurs !== [])
                        <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                            {{ ($compteurs['users'] ?? 0).' '.__('admin.scenario_manager.counter_users') }}
                            · {{ ($compteurs['loops'] ?? 0).' '.__('admin.scenario_manager.counter_loops') }}
                            · {{ ($compteurs['messages'] ?? 0).' '.__('admin.scenario_manager.counter_messages') }}
                            · {{ ($compteurs['dossiers'] ?? 0).' '.__('admin.scenario_manager.counter_dossiers') }}
                        </p>
                    @endif

                    <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">
                        @if($version->isLoaded())
                            {{ __('admin.scenario_manager.card_sandbox', ['slug' => $version->scenarioPackLoad?->organization?->slug ?? '—']) }}
                        @else
                            {{ __('admin.scenario_manager.card_no_sandbox') }}
                        @endif
                    </p>

                    <p class="mb-4 text-xs text-gray-400 dark:text-gray-500">
                        {{ __('admin.scenario_manager.card_modified', ['date' => $version->updated_at?->diffForHumans() ?? '—']) }}
                    </p>

                    <div class="mt-auto">
                        <a href="{{ route('admin.outils.scenarios.show', $version) }}"
                           class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            {{ __('admin.scenario_manager.card_open') }}
                        </a>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if($versions->hasPages())
        <div class="mt-6">{{ $versions->links() }}</div>
    @endif

    <p class="mt-6 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.loaded_explained') }}</p>
</x-admin-layout>
