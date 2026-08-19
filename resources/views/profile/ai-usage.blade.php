{{--
    « Mes usages IA » (TASK-1223, TASK-1228) — transparence, pas FinOps.

    Ce mois (fenetre du budget, UTC) : N utilisations, ventilation par nature,
    cout mesure, inconnus COMPTES. Puis l'historique recent en langage produit.
    Sources = l'autorite economique 1222 bornee a CET utilisateur (generation :
    registre des interactions ; recherches/indexations : registre canonique).
    Aucun chiffre a l'echelle de l'Organization. « — » = non mesure, jamais
    « 0 ». Aucun prompt, aucune reponse, aucun document, aucune cle.

    TASK-1257 (V2) : categories d'usage du mois (sous-lignes de « Generations »,
    meme autorite, elles SOMMENT la ligne) ; le $ est nomme cout FOURNISSEUR
    mesure, a titre d'information — jamais un prix facture (le credit est la
    seule unite de l'acces) ; exclusions du credit chiffrees sous la carte.
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

            {{-- TASK-1229 : CREDIT IA DU MOIS — en UTILISATIONS, jamais en dollars.
                 Le chiffre vient de la garde (AiEconomicGuard::userCreditStatus) :
                 c'est celui qui bloque. Trois etats : sous le seuil (reste visible),
                 seuil franchi (message calme, rien de bloque), plafond atteint
                 (refus clair + « Voir les offres »). Essais de doctrine et
                 indexations hors credit — l'ecran le dit. --}}
            @php
                $creditQuota = $credit->quota();
                $creditState = $credit->isUnlimited() ? 'unlimited' : ($credit->isExhausted() ? 'exhausted' : ($credit->isAlerting() ? 'alert' : 'ok'));
                $creditPercent = $credit->percent();
                $creditBar = $creditPercent === null ? 0 : min(100, $creditPercent);
                $creditTone = match ($creditState) {
                    'exhausted' => 'border-red-200 dark:border-red-900/50 bg-red-50/60 dark:bg-red-900/10',
                    'alert' => 'border-amber-200 dark:border-amber-900/50 bg-amber-50/60 dark:bg-amber-900/10',
                    default => 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800',
                };
                $creditBarTone = match ($creditState) {
                    'exhausted' => 'bg-red-500',
                    'alert' => 'bg-amber-500',
                    default => 'bg-emerald-500',
                };
            @endphp
            <section class="rounded-xl border p-6 mb-6 {{ $creditTone }}" data-my-ai-credit data-my-ai-credit-state="{{ $creditState }}" data-my-ai-credit-used="{{ $credit->used }}" data-my-ai-credit-quota="{{ $creditQuota ?? '' }}" data-my-ai-credit-remaining="{{ $credit->remaining() ?? '' }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.credit_title') }}</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $credit->policy->source === \App\Support\Ai\AiUserCreditPolicy::SOURCE_ORGANIZATION ? __('ai.credit_source_organization') : __('ai.credit_source_platform') }}</span>
                </div>

                @if($credit->isUnlimited())
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100" data-my-ai-credit-headline>{{ __('ai.credit_unlimited') }}</div>
                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">{{ __('ai.credit_unlimited_help') }}</p>
                @else
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100" data-my-ai-credit-headline>
                            {{ __('ai.credit_used_of_quota', ['used' => number_format($credit->used), 'quota' => number_format((int) $creditQuota)]) }}
                        </div>
                        <div class="text-sm font-medium {{ $creditState === 'exhausted' ? 'text-red-700 dark:text-red-300' : ($creditState === 'alert' ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300') }}" data-my-ai-credit-remaining-label>
                            {{ trans_choice('ai.credit_remaining', (int) $credit->remaining(), ['count' => number_format((int) $credit->remaining())]) }}
                        </div>
                    </div>
                    <div class="mt-3 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden" role="progressbar" aria-label="{{ __('ai.credit_progress_label') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $creditBar }}">
                        <div class="h-2 rounded-full {{ $creditBarTone }}" style="width: {{ $creditBar }}%"></div>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">{{ __('ai.credit_renews_at', ['date' => $credit->renewsAt->format('d/m/Y')]) }}</p>

                    @if($creditState === 'exhausted')
                        <div class="mt-4 rounded-lg border border-red-200 bg-white p-4 dark:border-red-900/50 dark:bg-gray-900/40" data-my-ai-credit-exhausted>
                            <p class="text-sm font-semibold text-red-800 dark:text-red-200">{{ (int) $creditQuota === 0 ? __('ai.credit_none_included') : __('ai.credit_exhausted_title') }}</p>
                            @if((int) $creditQuota > 0)
                                <p class="text-sm text-gray-700 dark:text-gray-300 mt-1">{{ __('ai.credit_exhausted_body', ['quota' => number_format((int) $creditQuota), 'date' => $credit->renewsAt->format('d/m/Y')]) }}</p>
                            @endif
                            @if($credit->policy->offerSubscription)
                                <a href="{{ $offersUrl }}" class="mt-3 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" data-ai-credit-offers-link>{{ __('ai.credit_see_offers') }}</a>
                            @endif
                        </div>
                    @elseif($creditState === 'alert')
                        <div class="mt-4 rounded-lg border border-amber-200 bg-white p-4 text-sm dark:border-amber-900/50 dark:bg-gray-900/40" data-my-ai-credit-alert>
                            <p class="font-semibold text-amber-800 dark:text-amber-200">{{ __('ai.credit_alert_title') }}</p>
                            <p class="text-gray-700 dark:text-gray-300 mt-1">{{ __('ai.credit_alert_remaining', ['remaining' => number_format((int) $credit->remaining()), 'used' => number_format($credit->used), 'quota' => number_format((int) $creditQuota)]) }}</p>
                        </div>
                    @endif
                @endif

                <p class="text-xs text-gray-500 dark:text-gray-400 mt-4">{{ __('ai.credit_intro') }}</p>
                {{-- TASK-1257 : ce que « Ce mois » compte et que le credit ne compte
                     pas, chiffre (comptes deja charges par summary()) — pour que
                     « Ce mois N » et « M sur Q » se lisent l'un par l'autre. --}}
                @if($usage['generation_sandbox']['trace_count'] > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" data-my-ai-credit-sandbox-excluded="{{ $usage['generation_sandbox']['trace_count'] }}">{{ trans_choice('ai.credit_out_of_scope_sandbox_count', $usage['generation_sandbox']['trace_count'], ['count' => number_format($usage['generation_sandbox']['trace_count'])]) }}</p>
                @endif
                @if($usage['embedding_ingestion']['invocation_count'] > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" data-my-ai-credit-ingestion-excluded="{{ $usage['embedding_ingestion']['invocation_count'] }}">{{ trans_choice('ai.credit_out_of_scope_ingestion_count', $usage['embedding_ingestion']['invocation_count'], ['count' => number_format($usage['embedding_ingestion']['invocation_count'])]) }}</p>
                @endif
                @if($usage['embedding_undeclared']['invocation_count'] > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" data-my-ai-credit-undeclared-excluded="{{ $usage['embedding_undeclared']['invocation_count'] }}">{{ trans_choice('ai.credit_out_of_scope_undeclared_count', $usage['embedding_undeclared']['invocation_count'], ['count' => number_format($usage['embedding_undeclared']['invocation_count'])]) }}</p>
                @endif
            </section>

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
                        {{-- TASK-1257 : cout FOURNISSEUR, information — jamais un prix facture. --}}
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-2" data-my-ai-usage-provider-cost-note>{{ __('ai.my_ai_usage_provider_cost_note') }}</div>
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
                            {{-- TASK-1257 : CATEGORIES D'USAGE — les generations du mois par
                                 fonction, en langage produit (meme autorite 1219, groupee par
                                 process) : les sous-lignes SOMMENT la ligne « Generations ». --}}
                            @if($nature['key'] === 'generation' && $categories !== [])
                                <li class="py-1 pl-4" data-my-ai-usage-categories data-my-ai-usage-categories-count="{{ count($categories) }}">
                                    <div class="text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.my_ai_usage_categories_title') }}</div>
                                    <ul class="space-y-1">
                                        @foreach($categories as $category)
                                            <li class="flex flex-wrap items-center justify-between gap-2 text-gray-700 dark:text-gray-300" data-my-ai-usage-category="{{ $category['key'] ?? 'other' }}" data-my-ai-usage-category-count="{{ $category['trace_count'] }}">
                                                <span>{{ $processLabel($category['key'], null, false) }}</span>
                                                <span class="font-mono text-xs">
                                                    {{ number_format($category['trace_count']) }} · {{ $costShort($category['known_cost_usd']) }}
                                                    @if($category['unknown_count'] > 0)
                                                        · <span class="text-amber-700 dark:text-amber-300">{{ trans_choice('ai.economy_unknown_count', $category['unknown_count'], ['count' => $category['unknown_count']]) }}</span>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endif
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
