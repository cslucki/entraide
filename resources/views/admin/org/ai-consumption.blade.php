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

    {{-- TASK-1228 : BUDGET PROVIDER DU MOIS + VENTILATION (autorite 1222) --}}
    @php
        $econTotalCount = $economics['generation']['trace_count']
            + $economics['embedding_query']['invocation_count']
            + $economics['embedding_ingestion']['invocation_count']
            + $economics['embedding_undeclared']['invocation_count'];
        $processLabel = static fn (?string $key): string => $key !== null && \Illuminate\Support\Facades\Lang::has('ai.process_label.'.str_replace('.', '_', $key))
            ? __('ai.process_label.'.str_replace('.', '_', $key))
            : ($key ?? '—');
    @endphp
    <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6" data-consumption-budget-block>
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.consumption_budget_title') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400" data-consumption-period>{{ __('ai.economy_period_label', ['from' => $filters->from->format('d/m/Y'), 'to' => $filters->to->subSecond()->format('d/m/Y')]) }}</span>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded-lg border border-gray-100 dark:border-gray-700 px-4 py-3">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.consumption_budget_monthly') }}</div>
                <div class="text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100" data-consumption-budget-monthly>{{ $budget['monthly_usd'] !== null ? '$'.number_format($budget['monthly_usd'], 2) : '—' }}</div>
            </div>
            <div class="rounded-lg border border-gray-100 dark:border-gray-700 px-4 py-3">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.consumption_budget_consumed') }}</div>
                <div class="text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100" data-consumption-budget-consumed>{{ $cost($budget['consumed_usd']) }}</div>
            </div>
            <div class="rounded-lg border border-gray-100 dark:border-gray-700 px-4 py-3">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.consumption_budget_remaining') }}</div>
                <div class="text-xl font-semibold tabular-nums {{ $budget['remaining_usd'] !== null && $budget['remaining_usd'] <= 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}" data-consumption-budget-remaining>{{ $budget['remaining_usd'] !== null ? '$'.number_format($budget['remaining_usd'], 6) : '—' }}</div>
            </div>
            <div class="rounded-lg border border-gray-100 dark:border-gray-700 px-4 py-3">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.consumption_budget_percent') }}</div>
                <div class="text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100" data-consumption-budget-percent>{{ $budget['percent'] !== null ? number_format($budget['percent'], 1).' %' : '—' }}</div>
                @if($budget['percent'] !== null)
                    <div class="mt-2 h-1.5 w-full rounded-full bg-gray-100 dark:bg-gray-700" aria-hidden="true">
                        <div class="h-1.5 rounded-full {{ $budget['percent'] >= 100 ? 'bg-red-500' : ($budget['percent'] >= 80 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ min(100, $budget['percent']) }}%"></div>
                    </div>
                @endif
            </div>
        </div>
        @if(! $isCurrentMonth)
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-3" data-consumption-budget-custom>{{ __('ai.consumption_budget_custom_period') }}</p>
        @elseif($budget['monthly_usd'] === null)
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-3" data-consumption-budget-none>{{ __('ai.consumption_budget_none') }}</p>
        @endif
        @if($economicsIgnoreDimensionFilters)
            <p class="text-xs text-amber-700 dark:text-amber-300 mt-2" data-consumption-economics-org-wide>{{ __('ai.consumption_economics_org_wide') }}</p>
        @endif

        {{-- Un cout non mesurable est COMPTE, a cote, visible. --}}
        <div class="mt-4 rounded-lg border p-3 text-sm {{ $economics['total_unknown_count'] > 0 || $economics['total_unevaluated_count'] > 0 ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200' : 'border-gray-100 dark:border-gray-700 text-gray-600 dark:text-gray-400' }}" data-consumption-economics-unknown="{{ $economics['total_unknown_count'] }}">
            {{ trans_choice('ai.economy_unknown_count', $economics['total_unknown_count'], ['count' => $economics['total_unknown_count']]) }}
            @if($economics['total_unevaluated_count'] > 0)
                · {{ trans_choice('ai.economy_unevaluated_count', $economics['total_unevaluated_count'], ['count' => $economics['total_unevaluated_count']]) }}
            @endif
        </div>

        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mt-6 mb-2">{{ __('ai.consumption_breakdown_title') }}</h3>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700 text-sm" data-consumption-breakdown data-consumption-total-count="{{ $econTotalCount }}">
            @foreach ([
                ['key' => 'generation', 'label' => __('ai.economy_nature_generation'), 'count' => $economics['generation']['trace_count'], 'known' => $economics['generation']['known_cost_usd'], 'unknown' => $economics['generation']['unknown_count']],
                ['key' => 'embedding_ingestion', 'label' => __('ai.economy_nature_embedding_ingestion'), 'count' => $economics['embedding_ingestion']['invocation_count'], 'known' => $economics['embedding_ingestion']['known_cost_usd'], 'unknown' => $economics['embedding_ingestion']['unknown_count']],
                ['key' => 'embedding_query', 'label' => __('ai.economy_nature_embedding_query'), 'count' => $economics['embedding_query']['invocation_count'], 'known' => $economics['embedding_query']['known_cost_usd'], 'unknown' => $economics['embedding_query']['unknown_count']],
                ['key' => 'embedding_undeclared', 'label' => __('ai.economy_nature_embedding_undeclared'), 'count' => $economics['embedding_undeclared']['invocation_count'], 'known' => $economics['embedding_undeclared']['known_cost_usd'], 'unknown' => $economics['embedding_undeclared']['unknown_count']],
            ] as $nature)
                @if($nature['count'] > 0 || $nature['key'] !== 'embedding_undeclared')
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-consumption-nature="{{ $nature['key'] }}" data-consumption-nature-count="{{ $nature['count'] }}">
                        <span class="text-gray-900 dark:text-gray-100">{{ $nature['label'] }}</span>
                        <span class="tabular-nums text-xs text-gray-700 dark:text-gray-300">
                            {{ number_format($nature['count']) }} · {{ $cost($nature['known']) }}
                            @if($nature['unknown'] > 0)
                                · <span class="text-amber-700 dark:text-amber-300">{{ trans_choice('ai.economy_unknown_count', $nature['unknown'], ['count' => $nature['unknown']]) }}</span>
                            @endif
                        </span>
                    </li>
                @endif
            @endforeach
            <li class="flex flex-wrap items-center justify-between gap-2 py-2 pl-4 text-gray-600 dark:text-gray-400" data-consumption-nature="sandbox" data-consumption-nature-count="{{ $economics['generation_sandbox']['trace_count'] }}">
                <span>{{ __('ai.economy_nature_sandbox') }}</span>
                <span class="tabular-nums text-xs">{{ number_format($economics['generation_sandbox']['trace_count']) }} · {{ $cost($economics['generation_sandbox']['known_cost_usd']) }}</span>
            </li>
        </ul>

        {{-- Utilisateurs les plus consommateurs (attribution prouvee ; non attribuable a part). --}}
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mt-6 mb-2">{{ __('ai.consumption_top_users_title') }}</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-consumption-top-users>
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_col_user') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_col_generation') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_col_search') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_col_indexing') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_col_known_cost') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.consumption_col_unknown') }}</th>
                        {{-- TASK-1229 : credit IA de chaque membre (utilisations creditees / quota effectif), mois courant seulement. --}}
                        @if($creditUses !== null)
                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.consumption_col_credit') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse(array_slice($economicsByUser, 0, 10) as $row)
                        @php
                            $creditUsed = $row['user_id'] !== null ? ($creditUses[$row['user_id']] ?? 0) : null;
                            $creditQuota = $creditPolicy->isUnlimited() ? null : (int) $creditPolicy->monthlyUses;
                            $creditBlocked = $creditUsed !== null && $creditQuota !== null && $creditUsed >= $creditQuota;
                            $creditAlert = $creditUsed !== null && $creditQuota !== null && $creditQuota > 0 && ! $creditBlocked && $creditUsed >= $creditQuota * $creditPolicy->alertPercent / 100;
                        @endphp
                        <tr data-consumption-top-user="{{ $row['user_id'] ?? 'unattributed' }}" @if($creditUsed !== null) data-consumption-credit-used="{{ $creditUsed }}" @endif>
                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $row['user_id'] === null ? __('ai.economy_unattributed') : ($row['name'] ?? '—') }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($row['generation']['trace_count']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($row['embedding_query']['invocation_count']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">
                                {{ number_format($row['embedding_ingestion']['invocation_count']) }}
                                @if($row['embedding_undeclared']['invocation_count'] > 0)
                                    <span class="text-gray-400" title="{{ __('ai.economy_nature_embedding_undeclared') }}">{{ __('ai.economy_undeclared_suffix', ['count' => number_format($row['embedding_undeclared']['invocation_count'])]) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">{{ $cost($row['total_known_cost_usd']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $row['total_unknown_count'] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">{{ number_format($row['total_unknown_count']) }}</td>
                            @if($creditUses !== null)
                                <td class="px-3 py-2 text-right tabular-nums text-xs {{ $creditBlocked ? 'text-red-600 dark:text-red-400 font-semibold' : ($creditAlert ? 'text-amber-700 dark:text-amber-300' : 'text-gray-700 dark:text-gray-300') }}">
                                    @if($creditUsed === null)
                                        —
                                    @elseif($creditQuota === null)
                                        {{ number_format($creditUsed) }} · {{ __('admin.consumption_credit_unlimited') }}
                                    @else
                                        {{ __('ai.credit_used_of_quota', ['used' => number_format($creditUsed), 'quota' => number_format($creditQuota)]) }}
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $creditUses !== null ? 7 : 6 }}" class="px-3 py-4 text-center text-gray-400">{{ __('ai.consumption_console_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-4">{{ __('ai.economy_authority_note') }}</p>
    </section>

    {{-- Detail des GENERATIONS (console 1219) --}}
    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('ai.consumption_generation_detail_title') }}</h2>
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
                                        @if($table['attr'] === 'process' && $row['key'] !== null)
                                            {{ $processLabel($row['key']) }} <span class="text-xs text-gray-400 font-mono">{{ $row['key'] }}</span>
                                        @else
                                            {{ $row['key'] ?? '—' }}
                                        @endif
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
