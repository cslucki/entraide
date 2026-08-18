{{--
    « Mes usages IA » (TASK-1223, TASK-1228) — transparence, pas FinOps.

    Ce mois (fenetre du budget, UTC) : N utilisations, ventilation par nature,
    cout mesure, inconnus COMPTES. Puis l'historique recent en langage produit.
    Sources = l'autorite economique 1222 bornee a CET utilisateur (generation :
    registre des interactions ; recherches/indexations : registre canonique).
    Aucun chiffre a l'echelle de l'Organization. « — » = non mesure, jamais
    « 0 ». Aucun prompt, aucune reponse, aucun document, aucune cle.
--}}
@php
    $cost = static fn ($value): string => $value === null ? '—' : '$'.number_format((float) $value, 10);
    $costShort = static fn ($value): string => $value === null ? '—' : '$'.number_format((float) $value, 6);
    $processLabel = static function (?string $process, ?string $feature, bool $sandbox): string {
        if ($sandbox) {
            return __('ai.activity_sandbox_label');
        }
        if ($process !== null && \Illuminate\Support\Facades\Lang::has('ai.process_label.'.str_replace('.', '_', $process))) {
            return __('ai.process_label.'.str_replace('.', '_', $process));
        }

        if ($process === null || $process === 'unknown') {
            return $feature ?? __('ai.process_label.other');
        }

        return $process;
    };
    $kindLabel = static fn (string $kind): string => match ($kind) {
        'generation' => __('ai.usage_type_generation'),
        'embedding_query' => __('ai.usage_type_embedding_query'),
        'embedding_ingestion' => __('ai.usage_type_embedding_ingestion'),
        default => __('ai.usage_type_embedding'),
    };
    $totalCount = $usage['generation']['trace_count']
        + $usage['embedding_query']['invocation_count']
        + $usage['embedding_ingestion']['invocation_count']
        + $usage['embedding_undeclared']['invocation_count'];
    $natures = [
        ['key' => 'generation', 'label' => __('ai.economy_nature_generation'), 'count' => $usage['generation']['trace_count'], 'known' => $usage['generation']['known_cost_usd'], 'unknown' => $usage['generation']['unknown_count']],
        ['key' => 'embedding_query', 'label' => __('ai.economy_nature_embedding_query'), 'count' => $usage['embedding_query']['invocation_count'], 'known' => $usage['embedding_query']['known_cost_usd'], 'unknown' => $usage['embedding_query']['unknown_count']],
        ['key' => 'embedding_ingestion', 'label' => __('ai.economy_nature_embedding_ingestion'), 'count' => $usage['embedding_ingestion']['invocation_count'], 'known' => $usage['embedding_ingestion']['known_cost_usd'], 'unknown' => $usage['embedding_ingestion']['unknown_count']],
        ['key' => 'embedding_undeclared', 'label' => __('ai.economy_nature_embedding_undeclared'), 'count' => $usage['embedding_undeclared']['invocation_count'], 'known' => $usage['embedding_undeclared']['known_cost_usd'], 'unknown' => $usage['embedding_undeclared']['unknown_count']],
    ];
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('ai.my_ai_usage_title') }}</x-slot>

    <x-page-container>
        <div class="max-w-5xl mx-auto py-8 px-4">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.my_ai_usage_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.my_ai_usage_scope_note') }}</p>
            </div>

            {{-- CE MOIS --}}
            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6" data-my-ai-usage-month data-my-ai-usage-month-count="{{ $totalCount }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.my_ai_usage_month_title') }}</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400" data-my-ai-usage-period>{{ __('ai.economy_period_label', ['from' => $period->from->format('d/m/Y'), 'to' => $period->to->subSecond()->format('d/m/Y')]) }}</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="rounded-lg border border-gray-100 dark:border-gray-700 p-4">
                        <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('ai.my_ai_usage_month_title') }}</div>
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1">{{ trans_choice('ai.my_ai_usage_month_count', $totalCount, ['count' => number_format($totalCount)]) }}</div>
                    </div>
                    <div class="rounded-lg border border-gray-100 dark:border-gray-700 p-4">
                        <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('ai.my_ai_usage_known_cost') }}</div>
                        <div class="text-2xl font-semibold font-mono text-gray-900 dark:text-gray-100 mt-1" data-my-ai-usage-known-cost>{{ $costShort($usage['total_known_cost_usd']) }}</div>
                        @if($usage['total_known_cost_usd'] === null)
                            <div class="text-xs text-gray-400 mt-1">{{ __('ai.economy_no_measured_cost') }}</div>
                        @endif
                    </div>
                    <div class="rounded-lg border p-4 {{ $usage['total_unknown_count'] > 0 ? 'border-amber-200 dark:border-amber-900/50 bg-amber-50/60 dark:bg-amber-900/10' : 'border-gray-100 dark:border-gray-700' }}" data-my-ai-usage-unknown="{{ $usage['total_unknown_count'] }}">
                        <div class="text-xs uppercase {{ $usage['total_unknown_count'] > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">{{ __('ai.my_ai_usage_unknown_title') }}</div>
                        <div class="text-sm font-medium mt-1 {{ $usage['total_unknown_count'] > 0 ? 'text-amber-800 dark:text-amber-200' : 'text-gray-700 dark:text-gray-300' }}">
                            {{ trans_choice('ai.economy_unknown_count', $usage['total_unknown_count'], ['count' => $usage['total_unknown_count']]) }}
                        </div>
                        @if($usage['total_unevaluated_count'] > 0)
                            <div class="text-xs text-amber-700 dark:text-amber-300 mt-1">{{ trans_choice('ai.economy_unevaluated_count', $usage['total_unevaluated_count'], ['count' => $usage['total_unevaluated_count']]) }}</div>
                        @endif
                    </div>
                </div>

                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mt-6 mb-2">{{ __('ai.my_ai_usage_breakdown_title') }}</h3>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700 text-sm" data-my-ai-usage-breakdown>
                    @foreach($natures as $nature)
                        @if($nature['count'] > 0 || in_array($nature['key'], ['generation', 'embedding_query'], true))
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-my-ai-usage-nature="{{ $nature['key'] }}" data-my-ai-usage-nature-count="{{ $nature['count'] }}">
                                <span class="text-gray-900 dark:text-gray-100">{{ $nature['label'] }}</span>
                                <span class="font-mono text-xs text-gray-700 dark:text-gray-300">
                                    {{ number_format($nature['count']) }} · {{ $costShort($nature['known']) }}
                                    @if($nature['unknown'] > 0)
                                        · <span class="text-amber-700 dark:text-amber-300">{{ trans_choice('ai.economy_unknown_count', $nature['unknown'], ['count' => $nature['unknown']]) }}</span>
                                    @endif
                                </span>
                            </li>
                        @endif
                    @endforeach
                    @if($usage['generation_sandbox']['trace_count'] > 0)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2 pl-4 text-gray-600 dark:text-gray-400" data-my-ai-usage-nature="sandbox" data-my-ai-usage-nature-count="{{ $usage['generation_sandbox']['trace_count'] }}">
                            <span>{{ __('ai.economy_nature_sandbox') }}</span>
                            <span class="font-mono text-xs">{{ number_format($usage['generation_sandbox']['trace_count']) }} · {{ $costShort($usage['generation_sandbox']['known_cost_usd']) }}</span>
                        </li>
                    @endif
                </ul>
                @if($totalCount === 0)
                    <p class="text-sm text-gray-400 mt-2">{{ __('ai.my_ai_usage_month_empty') }}</p>
                @endif
            </section>

            {{-- HISTORIQUE RECENT --}}
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('ai.my_ai_usage_recent_title') }}</h2>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden" data-my-ai-usage>
                @if($activity === [])
                    <div class="px-6 py-12 text-center text-gray-400" data-my-ai-usage-empty>
                        {{ __('ai.my_ai_usage_empty') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3 text-left">{{ __('ai.usage_col_date') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('ai.usage_col_action') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('ai.usage_col_type') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('ai.usage_col_provider_model') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('ai.usage_col_cost') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('ai.usage_col_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($activity as $row)
                                    <tr data-my-ai-usage-row data-my-ai-usage-kind="{{ $row['kind'] }}" data-my-ai-usage-feature="{{ $row['feature'] ?? $row['process'] ?? '' }}" data-my-ai-usage-cost-state="{{ $row['cost_state'] }}">
                                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $row['at']->format('d/m/Y H:i') }}</td>
                                        <td class="px-4 py-3 text-gray-900 dark:text-gray-100">
                                            {{ $processLabel($row['process'], $row['feature'], $row['sandbox']) }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $kindLabel($row['kind']) }}</td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 font-mono text-xs">{{ $row['provider'] ?? '—' }}{{ $row['model'] ? ' / '.$row['model'] : '' }}</td>
                                        <td class="px-4 py-3 text-right font-mono text-xs">
                                            @if($row['cost_state'] === 'known')
                                                <span class="text-gray-900 dark:text-gray-100">{{ $cost($row['cost_usd']) }}</span>
                                            @elseif($row['cost_state'] === 'unknown')
                                                <span class="text-amber-600 dark:text-amber-400" title="{{ trans_choice('ai.economy_unknown_count', 1, ['count' => 1]) }}">—</span>
                                            @else
                                                <span class="text-gray-400" title="{{ trans_choice('ai.economy_unevaluated_count', 1, ['count' => 1]) }}">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            @if($row['status'] === 'success')
                                                <span class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('ai.usage_status_success') }}</span>
                                            @elseif($row['status'] === null)
                                                <span class="text-xs text-gray-400">—</span>
                                            @else
                                                <span class="text-xs text-red-500">{{ __('ai.usage_status_failed') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <p class="text-xs text-gray-400 dark:text-gray-500 mt-4" data-my-ai-usage-note>
                {{ __('ai.economy_authority_note') }}
            </p>
        </div>
    </x-page-container>
</x-app-layout>
