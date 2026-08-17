{{--
    Cockpit IA/RAG plateforme (TASK-1223) — supervision par METADONNEES.

    Le SuperAdmin voit des comptes, des sommes de couts CONNUS et des jalons
    temporels par Organization. Jamais un contenu tenant (message, prompt,
    reponse, document, chunk), jamais une cle. « — » = non mesure, 0 = vrai
    zero, unknown != free.
--}}
@php
    $cost = static function ($value): string {
        return $value === null ? '—' : '$'.number_format((float) $value, 6);
    };
@endphp

<x-admin-layout>
    <x-slot name="title">{{ __('ai.platform_title') }}</x-slot>

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold dark:text-white">{{ __('ai.platform_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.platform_intro') }} ({{ $from->format('m/Y') }})</p>
        </div>

        {{-- Cards globales --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4" data-platform-cards>
            @foreach([
                ['label' => __('ai.platform_card_organizations'), 'value' => number_format($totals['organizations']), 'key' => 'organizations'],
                ['label' => __('ai.platform_card_configured'), 'value' => number_format($totals['configured']), 'key' => 'configured'],
                ['label' => __('ai.platform_card_invocations'), 'value' => number_format($totals['invocations']), 'key' => 'invocations'],
                ['label' => __('ai.platform_card_generation'), 'value' => number_format($totals['generation']), 'key' => 'generation'],
                ['label' => __('ai.platform_card_embeddings'), 'value' => number_format($totals['embeddings']), 'key' => 'embeddings'],
                ['label' => __('ai.platform_card_failed'), 'value' => number_format($totals['failed']), 'key' => 'failed'],
                ['label' => __('ai.platform_card_known_cost'), 'value' => $cost($totals['known_cost_usd']), 'key' => 'known-cost'],
                ['label' => __('ai.platform_card_unknown'), 'value' => number_format($totals['unknown_cost_count']), 'key' => 'unknown'],
            ] as $card)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4" data-platform-card="{{ $card['key'] }}">
                    <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                    <div class="text-xl font-semibold text-gray-900 dark:text-gray-100 mt-1 font-mono">{{ $card['value'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Table par Organization --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_organization') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_ready') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('ai.platform_col_provider_model') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_budget') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_generation') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_ingestion') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_query') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_failed') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_known_cost') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_unknown') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_chunks') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_sources') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('ai.platform_col_last_activity') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($organizations as $organization)
                            @php
                                $setting = $settings[(string) $organization->id] ?? null;
                                $usage = $ledger[(string) $organization->id] ?? null;
                                $index = $rag[(string) $organization->id] ?? null;
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition" data-platform-org="{{ $organization->id }}">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $organization->name }}</td>
                                <td class="px-4 py-3">
                                    @if($setting !== null && $setting['ready'])
                                        <span class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('ai.platform_yes') }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">{{ __('ai.platform_no') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $setting !== null ? $setting['provider'].' / '.$setting['model'] : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $setting !== null && $setting['monthly_budget_usd'] !== null ? '$'.number_format((float) $setting['monthly_budget_usd'], 2) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $usage !== null ? number_format($usage['generation_count']) : '0' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $usage !== null ? number_format($usage['embedding_ingestion_count']) : '0' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $usage !== null ? number_format($usage['embedding_query_count']) : '0' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ ($usage['failed_count'] ?? 0) > 0 ? 'text-red-500' : 'text-gray-400' }}">{{ $usage !== null ? number_format($usage['failed_count']) : '0' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $cost($usage['known_cost_usd'] ?? null) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ ($usage['unknown_cost_count'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400' }}">{{ $usage !== null ? number_format($usage['unknown_cost_count']) : '0' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $index !== null ? number_format($index['chunks']) : '0' }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $index !== null ? number_format($index['article_sources'] + $index['file_sources']) : '0' }}</td>
                                <td class="px-4 py-3 text-right text-xs text-gray-500 whitespace-nowrap">
                                    {{ $usage !== null && $usage['last_activity_at'] !== null ? $usage['last_activity_at']->format('d/m H:i') : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="px-4 py-8 text-center text-gray-400">{{ __('ai.platform_empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin-layout>
