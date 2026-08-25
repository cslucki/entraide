{{--
    « Economie IA BouclePro » — cockpit plateforme (TASK-1223, TASK-1228).

    Le SuperAdmin voit des comptes, des sommes de couts CONNUS et des jalons
    temporels par Organization. Jamais un contenu tenant (message, prompt,
    reponse, document, chunk, question posee), jamais une cle.
    « — » = non mesure, 0 = vrai zero, inconnu != gratuit.

    AUTORITE (TASK-1228) : les chiffres economiques viennent de
    `OrganizationAiEconomicUsage::perOrganization()` — la MEME autorite 1222
    que le releve de chaque Organization ; la ligne d'une Organization ICI est
    son releve, le total est la somme des lignes + « sans Organization ».

    TASK-1270 : chaque ligne porte un lien « Configurer » vers la SEULE surface
    d'ecriture du reglage IA, `/org/{organization}/admin/ai`
    (`organization.admin.ai`, OrgAdminController::ai/updateAi). Le SuperAdmin
    y entre par `OrgAdminMiddleware` (`is_admin`). Aucun formulaire de secret
    n'est duplique ici : ce listing ne rend ni ne recoit jamais une cle.
--}}
@php
    $cost = static function ($value): string {
        return $value === null ? '—' : '$'.number_format((float) $value, 6);
    };
    $unattributedCount = $unattributed['total_count'] ?? 0;
@endphp

<x-admin-layout>
    <x-slot name="title">{{ __('ai.platform_title') }}</x-slot>

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold dark:text-white">{{ __('ai.platform_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.platform_intro') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" data-platform-period>{{ __('ai.economy_period_label', ['from' => $from->format('d/m/Y'), 'to' => $to->subSecond()->format('d/m/Y')]) }}</p>
        </div>

        {{-- Cards globales : la meme autorite que chaque Organization. --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4" data-platform-cards>
            @foreach([
                ['label' => __('ai.platform_card_provider_cost'), 'value' => $cost($totals['known_cost_usd']), 'key' => 'known-cost', 'attr' => $totals['known_cost_usd']],
                ['label' => __('ai.platform_card_active_organizations'), 'value' => number_format($totals['active_organizations']).' '.__('ai.platform_of').' '.number_format($totals['organizations']), 'key' => 'active-organizations', 'attr' => $totals['active_organizations']],
                ['label' => __('ai.platform_card_ai_users'), 'value' => number_format($totals['ai_users']), 'key' => 'ai-users', 'attr' => $totals['ai_users']],
                ['label' => __('ai.platform_card_generation'), 'value' => number_format($totals['generation']), 'key' => 'generation', 'attr' => $totals['generation'], 'sub' => $totals['generation_sandbox'] > 0 ? __('ai.economy_nature_sandbox').' : '.number_format($totals['generation_sandbox']) : null],
                ['label' => __('ai.platform_card_search'), 'value' => number_format($totals['embedding_query']), 'key' => 'search', 'attr' => $totals['embedding_query']],
                ['label' => __('ai.platform_card_ingestion'), 'value' => number_format($totals['embedding_ingestion']), 'key' => 'ingestion', 'attr' => $totals['embedding_ingestion'], 'sub' => $totals['embedding_undeclared'] > 0 ? __('ai.economy_undeclared_suffix', ['count' => number_format($totals['embedding_undeclared'])]) : null],
                ['label' => __('ai.platform_card_unknown_cost'), 'value' => number_format($totals['unknown_count']), 'key' => 'unknown', 'attr' => $totals['unknown_count'], 'warn' => $totals['unknown_count'] > 0, 'sub' => $totals['unevaluated_count'] > 0 ? trans_choice('ai.economy_unevaluated_count', $totals['unevaluated_count'], ['count' => $totals['unevaluated_count']]) : null],
                ['label' => __('ai.platform_card_failed'), 'value' => number_format($totals['failed']), 'key' => 'failed', 'attr' => $totals['failed']],
            ] as $card)
                <div class="bg-white dark:bg-gray-800 rounded-xl border p-4 {{ ($card['warn'] ?? false) ? 'border-amber-300 dark:border-amber-700/60' : 'border-gray-200 dark:border-gray-700' }}" data-platform-card="{{ $card['key'] }}" data-platform-card-value="{{ $card['attr'] ?? '' }}">
                    <div class="text-xs uppercase {{ ($card['warn'] ?? false) ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">{{ $card['label'] }}</div>
                    <div class="text-xl font-semibold mt-1 font-mono {{ ($card['warn'] ?? false) ? 'text-amber-800 dark:text-amber-200' : 'text-gray-900 dark:text-gray-100' }}">{{ $card['value'] }}</div>
                    @if(! empty($card['sub']))
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $card['sub'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('ai.platform_card_configured') }} : {{ number_format($totals['configured']) }} ·
            {{ __('ai.platform_card_declared_budget') }} : {{ $totals['declared_budget_usd'] !== null ? '$'.number_format($totals['declared_budget_usd'], 2).' ('.number_format($totals['declared_budget_count']).')' : '—' }}
        </p>

        {{-- Table par Organization --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_organization') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_ready') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_budget') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_consumed') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_remaining') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_users') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_generation') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_rag') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_unknown') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_failed') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_chunks') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_last_activity') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_configure') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($organizations as $organization)
                            @php
                                $setting = $settings[(string) $organization->id] ?? null;
                                $eco = $economics[(string) $organization->id] ?? null;
                                $meta = $ledger[(string) $organization->id] ?? null;
                                $index = $rag[(string) $organization->id] ?? null;
                                $budget = $setting !== null && $setting['monthly_budget_usd'] !== null ? (float) $setting['monthly_budget_usd'] : null;
                                $consumed = $eco['total_known_cost_usd'] ?? null;
                                $remaining = $budget !== null ? $budget - (float) ($consumed ?? 0.0) : null;
                                $unknownCount = $eco['total_unknown_count'] ?? 0;
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition" data-platform-org="{{ $organization->id }}" data-platform-org-known-cost="{{ $consumed ?? '' }}" data-platform-org-unknown="{{ $unknownCount }}">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $organization->name }}
                                    <div class="font-mono text-[10px] text-gray-400">{{ $setting !== null ? $setting['provider'].' / '.$setting['model'] : '—' }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    @if($setting !== null && $setting['ready'])
                                        <span class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('ai.platform_yes') }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">{{ __('ai.platform_no') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-600 dark:text-gray-400">{{ $budget !== null ? '$'.number_format($budget, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $cost($consumed) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ $remaining !== null && $remaining <= 0 ? 'text-red-500' : 'text-gray-600 dark:text-gray-400' }}">{{ $remaining !== null ? '$'.number_format($remaining, 6) : '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($eco['ai_users_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">
                                    {{ number_format($eco['generation']['trace_count'] ?? 0) }}
                                    @if(($eco['generation_sandbox']['trace_count'] ?? 0) > 0)
                                        <span class="text-gray-400" title="{{ __('ai.economy_nature_sandbox') }}">({{ number_format($eco['generation_sandbox']['trace_count']) }})</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">
                                    {{ number_format($eco['embedding_query']['invocation_count'] ?? 0) }} / {{ number_format($eco['embedding_ingestion']['invocation_count'] ?? 0) }}
                                    @if(($eco['embedding_undeclared']['invocation_count'] ?? 0) > 0)
                                        <span class="text-gray-400" title="{{ __('ai.economy_nature_embedding_undeclared') }}">{{ __('ai.economy_undeclared_suffix', ['count' => number_format($eco['embedding_undeclared']['invocation_count'])]) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ $unknownCount > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-gray-400' }}">{{ number_format($unknownCount) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ ($meta['failed_count'] ?? 0) > 0 ? 'text-red-500' : 'text-gray-400' }}">{{ number_format($meta['failed_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $index !== null ? number_format($index['chunks']) : '0' }}</td>
                                <td class="px-4 py-3 text-right text-xs text-gray-500 whitespace-nowrap">
                                    {{ $meta !== null && $meta['last_activity_at'] !== null ? $meta['last_activity_at']->format('d/m H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    {{-- TASK-1270 : vers la surface existante de l'Organization, jamais un formulaire ici. --}}
                                    <a href="{{ route('organization.admin.ai', ['organization' => $organization->slug]) }}"
                                        class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 text-xs font-medium"
                                        title="{{ __('ai.platform_configure_title') }}"
                                        data-platform-org-configure="{{ $organization->slug }}">{{ __('ai.platform_configure') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="px-4 py-8 text-center text-gray-400">{{ __('ai.platform_empty') }}</td>
                            </tr>
                        @endforelse
                        @if($deletedCount > 0)
                            {{-- Organizations supprimees : hors de la liste, dans le total. --}}
                            <tr class="bg-gray-50 dark:bg-gray-900/30" data-platform-deleted="{{ $deletedCount }}">
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 italic" colspan="3">{{ trans_choice('ai.platform_row_deleted', $deletedCount, ['count' => $deletedCount]) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $cost($deleted['total_known_cost_usd']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($deleted['generation_count']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($deleted['embedding_query_count']) }} / {{ number_format($deleted['embedding_ingestion_count']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ $deleted['total_unknown_count'] > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-gray-400' }}">{{ number_format($deleted['total_unknown_count']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400" colspan="4">—</td>
                            </tr>
                        @endif
                        @if($unattributedCount > 0)
                            {{-- Traces sans Organization : comptees, jamais reparties. --}}
                            <tr class="bg-gray-50 dark:bg-gray-900/30" data-platform-unattributed="{{ $unattributedCount }}">
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 italic" colspan="3">{{ __('ai.platform_row_unattributed') }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $cost($unattributed['total_known_cost_usd']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ number_format($unattributed['generation']['trace_count']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ $unattributed['total_unknown_count'] > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-gray-400' }}">{{ number_format($unattributed['total_unknown_count']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-400" colspan="4">—</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.economy_authority_note') }}</p>
    </div>
</x-admin-layout>
