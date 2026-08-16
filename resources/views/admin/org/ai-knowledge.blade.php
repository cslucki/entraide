{{--
    Console RAG Organization V1 (TASK-1217), read-only.

    Deux niveaux volontairement separes : « Mes connaissances IA » repond a la
    question humaine (qu'est-ce que l'IA connait, est-ce indexe), et
    « Diagnostics techniques » reste en second plan pour qui veut verifier la
    coherence de l'index. Aucun statut n'est devine : ce qui n'est pas
    demontrable par une requete n'est pas affiche.
--}}
<x-org-admin-layout :title="__('navigation.org_admin_ai_knowledge')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_ai_knowledge') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.knowledge_console_intro') }}</p>
    </div>

    {{-- Resume --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        @foreach ([
            'knowledge_console_dossiers' => $summary['dossiers'],
            'knowledge_console_articles' => $summary['articles'],
            'knowledge_console_files' => $summary['files'],
            'knowledge_console_indexed_sources' => $summary['indexed_sources'],
            'knowledge_console_chunks' => $summary['chunks'],
        ] as $labelKey => $value)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-3">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.'.$labelKey) }}</div>
                <div class="text-xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($value) }}</div>
            </div>
        @endforeach
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 px-4 py-3">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_last_indexed') }}</div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                @if($summary['last_indexed_at'])
                    {{ \Illuminate\Support\Carbon::parse($summary['last_indexed_at'])->isoFormat('D MMM YYYY HH:mm') }}
                @else
                    <span class="text-xs text-gray-400">—</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Sources --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.knowledge_console_col_source') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.knowledge_console_col_type') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.knowledge_console_col_dossier') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.knowledge_console_col_state') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.knowledge_console_col_chunks') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.knowledge_console_col_model') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.knowledge_console_col_indexed_at') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('ai.knowledge_console_col_open') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($sources as $source)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750" data-rag-source>
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $source['title'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                {{ $source['type'] === 'article' ? __('ai.knowledge_console_type_article') : __('ai.knowledge_console_type_file') }}
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $source['dossier_name'] }}</td>
                            <td class="px-4 py-3">
                                @if($source['indexed'])
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/40 dark:text-green-300">{{ __('ai.knowledge_console_state_indexed') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('ai.knowledge_console_state_not_indexed') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums text-gray-600 dark:text-gray-400">{{ $source['chunks'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                @if($source['embedding_model'])
                                    <span class="text-xs">{{ $source['embedding_model'] }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                @if($source['indexed_at'])
                                    <span class="text-xs">{{ \Illuminate\Support\Carbon::parse($source['indexed_at'])->isoFormat('D MMM YYYY HH:mm') }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                {{-- Le lien n'existe que si la DossierPolicy autorise
                                     vraiment cet utilisateur a voir le Dossier : etre
                                     admin ne donne pas acces au contenu prive. --}}
                                @if($source['can_open'])
                                    @if($source['type'] === 'article')
                                        <a href="{{ route('organization.blog.show', ['organization' => $organization->slug, 'post' => $source['slug']]) }}"
                                           target="_blank" rel="noopener"
                                           class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs">{{ __('ai.knowledge_console_open') }}</a>
                                    @else
                                        <a href="{{ route('organization.dossiers.files.show', ['organization' => $organization->slug, 'dossier' => $source['dossier_id'], 'file' => $source['id']]) }}"
                                           class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs">{{ __('ai.knowledge_console_download') }}</a>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400" title="{{ __('ai.knowledge_console_open_denied_hint') }}">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Diagnostics techniques, volontairement en second plan --}}
    <details class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden" data-rag-diagnostics>
        <summary class="px-4 py-3 cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-750">
            {{ __('ai.knowledge_console_diagnostics') }}
        </summary>
        <div class="px-4 py-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
            @if($diagnostics['index_mismatch'])
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200" data-rag-mismatch>
                    {{ __('ai.knowledge_console_mismatch_warning') }}
                    <ul class="mt-1 list-disc list-inside text-xs">
                        @if($diagnostics['provider_mismatch'])
                            <li data-rag-mismatch-provider>{{ __('ai.knowledge_console_mismatch_provider') }}</li>
                        @endif
                        @if($diagnostics['model_mismatch'])
                            <li data-rag-mismatch-model>{{ __('ai.knowledge_console_mismatch_model') }}</li>
                        @endif
                    </ul>
                </div>
            @endif

            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-2 text-sm">
                <div class="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700 py-1">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_chunks') }}</dt>
                    <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($diagnostics['chunks']) }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700 py-1">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_distinct_articles') }}</dt>
                    <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($diagnostics['distinct_articles']) }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700 py-1">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_distinct_files') }}</dt>
                    <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($diagnostics['distinct_files']) }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700 py-1">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_index_family') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100">{{ $diagnostics['index_family'] ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700 py-1">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_index_model') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100 text-xs">{{ $diagnostics['index_model'] ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700 py-1">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_stored_providers') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100 text-xs" data-rag-stored-providers>{{ $diagnostics['providers'] ? implode(', ', $diagnostics['providers']) : '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 dark:border-gray-700 py-1">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_stored_models') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100 text-xs" data-rag-stored-models>{{ $diagnostics['models'] ? implode(', ', $diagnostics['models']) : '—' }}</dd>
                </div>
            </dl>

            <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('ai.knowledge_console_diagnostics_note') }}</p>
        </div>
    </details>
</x-org-admin-layout>
