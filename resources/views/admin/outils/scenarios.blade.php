{{-- TASK-1646 — la surface de la FONDATION.
     Cet ecran repond a une seule question : « la persistance administrative
     des versions de scenario existe-t-elle vraiment ? ». Il ne cree rien, ne
     modifie rien, n'ouvre aucune sandbox. La bibliotheque riche, la
     previsualisation, l'edition, le chargement et la capture arrivent en
     T1648..T1653 (CDC Scenario Manager, section 35).

     La colonne « Etat » merite une attention : elle affiche trois valeurs mais
     la base n'en stocke que DEUX. « Charge » est derive par
     ScenarioManifestVersion::isLoaded(), jamais lu dans une colonne. --}}
<x-admin-layout :title="__('admin.scenario_manager.title')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.subtitle') }}</p>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.read_only') }}</p>
    </div>

    {{-- Le perimetre reel de la tache, dit franchement : mieux vaut un bandeau
         explicite qu'un ecran qui laisse croire a des actions absentes. --}}
    <div class="mb-8 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
        <p class="text-sm text-amber-900 dark:text-amber-200">{{ __('admin.scenario_manager.foundation_notice') }}</p>
    </div>

    <div class="mb-8 grid grid-cols-2 gap-3 sm:grid-cols-5">
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

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_scenario') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_version') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_usage') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_origin') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_state') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_digest') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_sandbox') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.col_author') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($versions as $version)
                    <tr>
                        <td class="px-3 py-3">
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $version->name }}</span>
                            <span class="block font-mono text-xs text-gray-400 dark:text-gray-500">{{ $version->scenario_key }}</span>
                        </td>
                        <td class="px-3 py-3 font-mono text-gray-700 dark:text-gray-300">{{ $version->version }}</td>
                        <td class="px-3 py-3 text-gray-600 dark:text-gray-400">{{ __('admin.scenario_manager.usage_'.$version->usage) }}</td>
                        <td class="px-3 py-3 text-gray-600 dark:text-gray-400">{{ __('admin.scenario_manager.origin_'.$version->origin) }}</td>
                        <td class="px-3 py-3">
                            {{-- Trois libelles pour deux colonnes : « Charge » vient du
                                 predicat derive, pas de `state`. --}}
                            {{-- `data-state` est un crochet SEMANTIQUE : il dit l'etat
                                 rendu, independamment de la couleur qui l'habille. Un
                                 test qui compterait une classe Tailwind serait faux le
                                 jour ou le layout emploierait la meme. --}}
                            @if($version->isLoaded())
                                <span data-state="loaded" class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-1 text-xs font-semibold text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">{{ __('admin.scenario_manager.state_loaded') }}</span>
                            @elseif($version->isValid())
                                <span data-state="valid" class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-300">{{ __('admin.scenario_manager.state_valid') }}</span>
                            @else
                                <span data-state="draft" class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.state_draft') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">
                            {{ $version->digest ? Str::limit($version->digest, 12, '…') : __('admin.scenario_manager.no_digest') }}
                        </td>
                        <td class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400">
                            {{ $version->scenarioPackLoad?->organization?->slug ?? __('admin.scenario_manager.no_sandbox') }}
                        </td>
                        <td class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400">
                            {{ $version->author?->full_name ?? __('admin.scenario_manager.no_author') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-10 text-center">
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.empty') }}</p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.empty_hint') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($versions->hasPages())
        <div class="mt-4">{{ $versions->links() }}</div>
    @endif

    <p class="mt-6 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.loaded_explained') }}</p>
</x-admin-layout>
