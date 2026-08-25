{{--
    TASK-1307 — resultat de la recherche documentaire BRUTE (pgvector seul,
    aucune generation LLM). Rend rank / distance / source / chunk / extrait,
    exactement ce qu'un appelant `searchAcrossDossiers()` recoit.
--}}
<div data-search-result-generated>
    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
        {{ __('ai.knowledge_console_search_query_label') }} : <span class="font-medium text-gray-800 dark:text-gray-200">{{ $query }}</span>
        @if($loopId)
            · {{ __('ai.observatory_col_scope') }} : <span class="font-medium text-gray-800 dark:text-gray-200">{{ $loops->firstWhere('id', $loopId)?->name ?? $loopId }}</span>
        @endif
    </p>

    @if(! $result['ran'])
        <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">
            {{ __('ai.knowledge_console_search_reason_'.$result['reason']) }}
        </p>
    @elseif($result['rows'] === [])
        <p class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
            {{ __('ai.knowledge_console_search_no_results') }}
        </p>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">#</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_search_col_distance') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_source') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_dossier') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_search_col_chunk') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_search_col_excerpt') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($result['rows'] as $row)
                        <tr class="{{ $row['rank'] <= 5 ? '' : 'opacity-60' }}">
                            <td class="px-3 py-2 tabular-nums text-gray-500 dark:text-gray-400">{{ $row['rank'] }}</td>
                            <td class="px-3 py-2 tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($row['distance'], 4) }}</td>
                            <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ $row['title'] }}</td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $row['dossier_name'] }}</td>
                            <td class="px-3 py-2 tabular-nums text-gray-500 dark:text-gray-400">{{ $row['chunk_index'] }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $row['extrait'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">{{ __('ai.knowledge_console_search_top5_note') }}</p>
    @endif
</div>
