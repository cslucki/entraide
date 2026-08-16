{{--
    Console « Consommation IA » Organization V1 (TASK-1219), read-only.

    Regle d'affichage unique, appliquee partout : un cout mesure s'affiche, un
    cout absent s'affiche « — », et JAMAIS « 0,00 $ ». Les deux se lisent
    autrement : « 0 » dit « ca n'a rien coute », « — » dit « on ne sait pas ».
    Les appels non mesurables ont donc leur propre colonne, a cote du cout,
    jamais fondus dedans.
--}}
@php
    /**
     * Rendu d'un cout. `null` (aucune ligne mesuree) devient « — ».
     * Six decimales parce que la colonne en porte six : une console de
     * metrologie affiche la precision qu'elle possede, pas une precision ronde.
     */
    $cost = static function (?float $value): string {
        return $value === null ? '—' : '$'.number_format($value, 6);
    };
@endphp

<x-org-admin-layout :title="__('navigation.org_admin_ai_consumption')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_ai_consumption') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.consumption_console_intro') }}</p>
    </div>

    {{-- Filtres --}}
    <form method="GET" action="{{ route('organization.admin.ai-consumption', ['organization' => $organization->slug]) }}"
          class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 mb-6"
          data-consumption-filters>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            <div>
                <label for="from" class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.consumption_console_from') }}</label>
                <input type="date" id="from" name="from" value="{{ $filters->from->toDateString() }}"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
            </div>
            <div>
                <label for="to" class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.consumption_console_to') }}</label>
                {{-- Borne INCLUSIVE cote interface : l'utilisateur relit la date qu'il a saisie. --}}
                <input type="date" id="to" name="to" value="{{ $filters->to->subDay()->toDateString() }}"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
            </div>
            <div>
                <label for="user_id" class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.consumption_console_filter_user') }}</label>
                <select id="user_id" name="user_id" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                    <option value="">{{ __('ai.consumption_console_all') }}</option>
                    @foreach ($available['users'] as $option)
                        <option value="{{ $option['id'] }}" @selected($filters->userId === $option['id'])>{{ $option['name'] ?? '—' }}</option>
                    @endforeach
                </select>
            </div>
            @foreach ([
                'process' => ['label' => 'consumption_console_filter_process', 'options' => $available['processes'], 'current' => $filters->process],
                'model' => ['label' => 'consumption_console_filter_model', 'options' => $available['models'], 'current' => $filters->model],
                'provider' => ['label' => 'consumption_console_filter_provider', 'options' => $available['providers'], 'current' => $filters->provider],
            ] as $name => $config)
                <div>
                    <label for="{{ $name }}" class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.'.$config['label']) }}</label>
                    <select id="{{ $name }}" name="{{ $name }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm">
                        <option value="">{{ __('ai.consumption_console_all') }}</option>
                        @foreach ($config['options'] as $option)
                            <option value="{{ $option }}" @selected($config['current'] === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>
        <div class="flex items-center gap-3 mt-4">
            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color: var(--bp-primary)">
                {{ __('ai.consumption_console_filter') }}
            </button>
            <a href="{{ route('organization.admin.ai-consumption', ['organization' => $organization->slug]) }}"
               class="text-sm text-gray-500 dark:text-gray-400 hover:underline">{{ __('ai.consumption_console_reset') }}</a>
        </div>
    </form>

    {{-- Resume --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-3">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.consumption_console_known_cost') }}</div>
            <div class="text-xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums" data-consumption-known-cost>{{ $cost($summary['known_cost_usd']) }}</div>
        </div>
        @foreach ([
            'consumption_console_measured' => ['value' => $summary['measured_count'], 'attr' => 'data-consumption-measured'],
            'consumption_console_unknown' => ['value' => $summary['unknown_count'], 'attr' => 'data-consumption-unknown'],
            'consumption_console_unevaluated' => ['value' => $summary['unevaluated_count'], 'attr' => 'data-consumption-unevaluated'],
            'consumption_console_traces' => ['value' => $summary['trace_count'], 'attr' => 'data-consumption-traces'],
        ] as $labelKey => $card)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-3">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.'.$labelKey) }}</div>
                <div class="text-xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums" {{ $card['attr'] }}>{{ number_format($card['value']) }}</div>
            </div>
        @endforeach
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-3">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.consumption_console_budget') }}</div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 tabular-nums" data-consumption-budget>
                @if($monthlyBudgetUsd !== null)
                    ${{ number_format((float) $monthlyBudgetUsd, 2) }}
                @else
                    <span class="text-xs text-gray-400">—</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Un cout non mesurable n'est pas un cout nul : on le dit des qu'il y en a. --}}
    @if($summary['unknown_count'] > 0 || $summary['unevaluated_count'] > 0)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200 mb-6"
             data-consumption-unknown-hint>
            {{ __('ai.consumption_console_unknown_hint') }}
        </div>
    @endif

    @if($summary['trace_count'] === 0)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400 mb-6"
             data-consumption-empty>
            {{ __('ai.consumption_console_empty') }}
        </div>
    @else
        {{-- Ventilations : meme forme partout, cout mesure d'un cote, non mesurables de l'autre. --}}
        @foreach ([
            ['title' => 'consumption_console_by_process', 'rows' => $byProcess, 'attr' => 'process'],
            ['title' => 'consumption_console_by_model', 'rows' => $byModel, 'attr' => 'model'],
            ['title' => 'consumption_console_by_provider', 'rows' => $byProvider, 'attr' => 'provider'],
        ] as $table)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.'.$table['title']) }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.'.$table['title']) }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_known_cost') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_measured') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_unknown') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_unevaluated') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_traces') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($table['rows'] as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750" data-consumption-row="{{ $table['attr'] }}">
                                    <td class="px-4 py-3 text-gray-900 dark:text-gray-100">
                                        {{-- Valeur absente : « — », jamais devinee depuis une autre colonne. --}}
                                        {{ $row['key'] ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ $cost($row['known_cost_usd']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['measured_count']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['unknown_count']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['unevaluated_count']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($row['trace_count']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        {{-- Par utilisateur : l'attribution vient de la colonne `user_id`, jamais d'une reconstruction. --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.consumption_console_by_user') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_user') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_known_cost') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_measured') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_unknown') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_unevaluated') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_traces') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($byUser as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750" data-consumption-row="user">
                                <td class="px-4 py-3 text-gray-900 dark:text-gray-100">{{ $row['name'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ $cost($row['known_cost_usd']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['measured_count']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['unknown_count']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['unevaluated_count']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($row['trace_count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Par jour : lire une derive dans le temps. --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.consumption_console_by_day') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_day') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_known_cost') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_measured') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_unknown') }}</th>
                            {{-- Sans cette colonne, mesures + non mesurables ne feraient pas le
                                 total des appels : une ligne de metrologie qui ne s'additionne
                                 pas est exactement l'incoherence que cette console combat. --}}
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_unevaluated') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_console_col_traces') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($byDay as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750" data-consumption-row="day">
                                <td class="px-4 py-3 text-gray-900 dark:text-gray-100 tabular-nums">{{ \Illuminate\Support\Carbon::parse($row['day'])->isoFormat('D MMM YYYY') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ $cost($row['known_cost_usd']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['measured_count']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['unknown_count']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['unevaluated_count']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($row['trace_count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Ce que la page NE dit pas : ecrit a l'ecran, pas seulement dans le code. --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4" data-consumption-limits>
        <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">{{ __('ai.consumption_console_scope_note') }}</p>
        <h2 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">{{ __('ai.consumption_console_limits_title') }}</h2>
        <ul class="list-disc list-inside space-y-1 text-sm text-gray-500 dark:text-gray-400">
            <li>{{ __('ai.consumption_console_limit_cost_origin') }}</li>
            <li>{{ __('ai.consumption_console_limit_platform_price') }}</li>
            <li>{{ __('ai.consumption_console_limit_tokens') }}</li>
            <li>{{ __('ai.consumption_console_limit_credential') }}</li>
        </ul>
    </div>
</x-org-admin-layout>
