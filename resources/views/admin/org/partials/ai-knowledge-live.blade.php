{{--
    Observatoire des connaissances — fragment vivant (TASK-1226).

    Rendu deux fois par le meme code : inclus dans la page complete, et renvoye
    tel quel par `organization.admin.ai-knowledge.live` a chaque poll. Une
    seule source de rendu, donc jamais deux verites.

    Tout ce qui est affiche ici est prouve par une requete locale : etat
    indexe / non indexe (lignes dossier_chunks), nombre d'extraits, derniere
    indexation, perimetre (racine gouvernante du Dossier), etat de
    l'infrastructure au present (autorites existantes). Rien n'est devine, rien
    n'est lu dans les logs, aucun contenu RAG ni chemin disque n'en sort.
--}}
@php
    $recentWindowSeconds = 120;
    $isRecent = static function (?string $timestamp) use ($generatedAt, $recentWindowSeconds): bool {
        if ($timestamp === null) {
            return false;
        }

        try {
            return \Illuminate\Support\Carbon::parse($timestamp)->diffInSeconds($generatedAt, true) <= $recentWindowSeconds;
        } catch (\Throwable) {
            return false;
        }
    };
    $secondsSince = static function (?string $timestamp) use ($generatedAt): int {
        return $timestamp === null ? 0 : (int) max(0, \Illuminate\Support\Carbon::parse($timestamp)->diffInSeconds($generatedAt, true));
    };
    $scopeLabel = static function (array $scope): string {
        return match ($scope['kind']) {
            \App\Services\Dossiers\OrganizationRagOverview::SCOPE_ORGANIZATION => __('ai.observatory_scope_organization'),
            \App\Services\Dossiers\OrganizationRagOverview::SCOPE_LOOP => __('ai.observatory_scope_loop', ['name' => $scope['loop_name']]),
            \App\Services\Dossiers\OrganizationRagOverview::SCOPE_LOOP_SHARED => __('ai.observatory_scope_loop_shared', ['name' => $scope['loop_name']]),
            default => __('ai.observatory_scope_private'),
        };
    };
    $scopeClasses = static function (array $scope): string {
        return match ($scope['kind']) {
            \App\Services\Dossiers\OrganizationRagOverview::SCOPE_ORGANIZATION => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
            \App\Services\Dossiers\OrganizationRagOverview::SCOPE_LOOP => 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200',
            \App\Services\Dossiers\OrganizationRagOverview::SCOPE_LOOP_SHARED => 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-300 dark:bg-violet-900/20 dark:text-violet-200 dark:ring-violet-700',
            default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        };
    };
    $formatLabel = static fn (string $format): string => __('ai.observatory_format_'.$format);
    $infraMessages = [];
    if (! $availability['semantic_search_enabled']) {
        $infraMessages['disabled'] = __('ai.observatory_infra_disabled');
    }
    if (! $availability['embedding_credential_available']) {
        $infraMessages['no_credential'] = __('ai.observatory_infra_no_credential');
    }
    if (! $availability['budget_allows_indexing']) {
        $infraMessages['budget'] = __('ai.observatory_infra_budget');
    }
    $sourceCountLabel = static fn (int $count): string => trans_choice('ai.observatory_sources_count', $count, ['count' => number_format($count)]);
    $chunkCountLabel = static fn (int $count): string => trans_choice('ai.observatory_chunks_count', $count, ['count' => number_format($count)]);
@endphp
<div data-knowledge-generated-at="{{ $generatedAt->toIso8601String() }}" data-knowledge-source-count="{{ count($sources) }}">

    {{-- Etat de l'infrastructure — niveau ORGANIZATION, au present. Jamais
         impute a une source : « non indexee » reste l'etat de la source. --}}
    @if($infraMessages !== [])
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-700/50 dark:bg-amber-900/20" data-knowledge-infra="unavailable">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">{{ __('ai.observatory_infra_title') }}</p>
                    <ul class="mt-1 space-y-1 text-sm text-amber-800 dark:text-amber-200">
                        @foreach($infraMessages as $key => $message)
                            <li data-knowledge-infra-reason="{{ $key }}">{{ $message }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-amber-700/80 dark:text-amber-300/80">{{ __('ai.observatory_infra_note') }}</p>
                </div>
                <a href="{{ route('organization.admin.ai', ['organization' => $organization->slug]) }}"
                   class="flex-shrink-0 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-medium text-amber-900 hover:bg-amber-100 dark:border-amber-600 dark:bg-transparent dark:text-amber-100 dark:hover:bg-amber-900/40">{{ __('ai.observatory_infra_configure') }}</a>
            </div>
        </div>
    @else
        <p class="mb-6 flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300" data-knowledge-infra="available">
            <span class="inline-block h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
            {{ __('ai.observatory_infra_ok') }}
        </p>
    @endif

    {{-- Perimetres — uniquement les espaces reellement determinables. --}}
    <section class="mb-6" aria-labelledby="knowledge-perimeters-title">
        <div class="mb-3">
            <h2 id="knowledge-perimeters-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.observatory_perimeters') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.observatory_perimeters_hint') }}</p>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {{-- Organization --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800" data-knowledge-perimeter="organization">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-sky-500" aria-hidden="true"></span>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.observatory_perimeter_organization') }}</h3>
                </div>
                <p class="mt-2 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                    <span data-perimeter-sources>{{ $sourceCountLabel($perimeters['organization']['sources']) }}</span>
                    <span class="text-gray-400 dark:text-gray-500">·</span>
                    <span data-perimeter-chunks class="text-base font-medium text-gray-700 dark:text-gray-300">{{ $chunkCountLabel($perimeters['organization']['chunks']) }}</span>
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.observatory_perimeter_organization_hint') }}</p>
            </div>

            {{-- Boucles --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800 sm:col-span-2 xl:col-span-2" data-knowledge-perimeter="loops">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-violet-500" aria-hidden="true"></span>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.observatory_perimeter_loops') }}</h3>
                </div>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.observatory_perimeter_loops_hint') }}</p>
                @if($perimeters['loops'] === [])
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('ai.observatory_perimeter_loops_empty') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700/70">
                        @foreach($perimeters['loops'] as $loopPerimeter)
                            <li class="flex flex-col gap-0.5 py-1.5 text-sm sm:flex-row sm:items-baseline sm:justify-between sm:gap-4" data-knowledge-loop="{{ $loopPerimeter['loop_id'] }}">
                                <span class="min-w-0 flex-1 truncate font-medium text-gray-800 dark:text-gray-200" title="{{ $loopPerimeter['name'] }}">{{ $loopPerimeter['name'] }}</span>
                                <span class="shrink-0 tabular-nums text-gray-600 dark:text-gray-400 sm:whitespace-nowrap sm:text-right">
                                    {{ $sourceCountLabel($loopPerimeter['sources']) }} · {{ $chunkCountLabel($loopPerimeter['chunks']) }}
                                    @if($loopPerimeter['shared_sources'] > 0)
                                        <span class="text-xs text-violet-700 dark:text-violet-300">({{ trans_choice('ai.observatory_perimeter_shared_count', $loopPerimeter['shared_sources'], ['count' => $loopPerimeter['shared_sources']]) }})</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Utilisateurs / prive --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800" data-knowledge-perimeter="private">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-gray-400" aria-hidden="true"></span>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.observatory_perimeter_private') }}</h3>
                </div>
                <p class="mt-2 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                    <span data-perimeter-sources>{{ $sourceCountLabel($perimeters['private']['sources']) }}</span>
                    <span class="text-gray-400 dark:text-gray-500">·</span>
                    <span data-perimeter-chunks class="text-base font-medium text-gray-700 dark:text-gray-300">{{ $chunkCountLabel($perimeters['private']['chunks']) }}</span>
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.observatory_perimeter_private_hint') }}</p>
            </div>

            {{-- Sources externes : aucun connecteur reel, et on le dit. --}}
            <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50/60 p-4 dark:border-gray-600 dark:bg-gray-800/40 sm:col-span-2 xl:col-span-4" data-knowledge-perimeter="external">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('ai.observatory_perimeter_external') }}</h3>
                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('ai.observatory_perimeter_external_none') }}</span>
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('ai.observatory_perimeter_external_future') }}</span>
                </div>
            </div>
        </div>
    </section>

    {{-- Resume Organization --}}
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            'knowledge_console_dossiers' => $summary['dossiers'],
            'knowledge_console_articles' => $summary['articles'],
            'knowledge_console_files' => $summary['files'],
            'knowledge_console_indexed_sources' => $summary['indexed_sources'],
            'knowledge_console_chunks' => $summary['chunks'],
        ] as $labelKey => $value)
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800" data-knowledge-counter="{{ $labelKey }}">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.'.$labelKey) }}</div>
                <div class="text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100" data-knowledge-counter-value>{{ number_format($value) }}</div>
            </div>
        @endforeach
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800" data-knowledge-counter="last_indexed">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_last_indexed') }}</div>
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100" data-knowledge-counter-value>
                @if($summary['last_indexed_at'])
                    {{ \Illuminate\Support\Carbon::parse($summary['last_indexed_at'])->isoFormat('D MMM YYYY HH:mm:ss') }}
                @else
                    <span class="text-xs text-gray-400">—</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Sources --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-knowledge-sources>
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_source') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_type') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_dossier') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.observatory_col_scope') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_state') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_chunks') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_indexed_at') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_col_open') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($sources as $source)
                        @php
                            $sourceKey = $source['type'].':'.$source['id'];
                            $recentlyAppeared = $isRecent($source['created_at'] ?? null);
                            $recentlyIndexed = $source['indexed'] && $isRecent($source['indexed_at']);
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition-colors duration-700"
                            data-rag-source
                            data-source-key="{{ $sourceKey }}" data-source-indexed="{{ $source['indexed'] ? 1 : 0 }}" data-source-chunks="{{ $source['chunks'] }}">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                <span>{{ $source['title'] }}</span>
                                @if($recentlyAppeared)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300" data-source-new>{{ __('ai.observatory_new_source') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $formatLabel($source['format']) }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $source['dossier_name'] }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex max-w-[14rem] items-center truncate rounded-full px-2 py-0.5 text-xs font-medium {{ $scopeClasses($source['scope']) }}" data-source-scope="{{ $source['scope']['kind'] }}" title="{{ $scopeLabel($source['scope']) }}">{{ $scopeLabel($source['scope']) }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if($source['indexed'])
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/40 dark:text-green-300" title="{{ $source['embedding_model'] }}">{{ __('ai.knowledge_console_state_indexed') }}</span>
                                    @if($recentlyIndexed)
                                        <span class="ml-1 text-[11px] text-emerald-700 dark:text-emerald-300" data-source-indexed-ago>{{ __('ai.observatory_indexed_ago', ['seconds' => $secondsSince($source['indexed_at'])]) }}</span>
                                    @endif
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('ai.knowledge_console_state_not_indexed') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums text-gray-600 dark:text-gray-400">{{ $source['chunks'] }}</td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                @if($source['indexed_at'])
                                    <span class="text-xs">{{ \Illuminate\Support\Carbon::parse($source['indexed_at'])->isoFormat('D MMM YYYY HH:mm:ss') }}</span>
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
                                           class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ __('ai.knowledge_console_open') }}</a>
                                    @else
                                        <a href="{{ route('organization.dossiers.files.show', ['organization' => $organization->slug, 'dossier' => $source['dossier_id'], 'file' => $source['id']]) }}"
                                           class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ __('ai.knowledge_console_download') }}</a>
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
    <details class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800" data-rag-diagnostics>
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-750">
            {{ __('ai.knowledge_console_diagnostics') }}
        </summary>
        <div class="space-y-3 border-t border-gray-200 px-4 py-4 dark:border-gray-700">
            @if($diagnostics['index_mismatch'])
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200" data-rag-mismatch>
                    {{ __('ai.knowledge_console_mismatch_warning') }}
                    <ul class="mt-1 list-inside list-disc text-xs">
                        @if($diagnostics['provider_mismatch'])
                            <li data-rag-mismatch-provider>{{ __('ai.knowledge_console_mismatch_provider') }}</li>
                        @endif
                        @if($diagnostics['model_mismatch'])
                            <li data-rag-mismatch-model>{{ __('ai.knowledge_console_mismatch_model') }}</li>
                        @endif
                    </ul>
                </div>
            @endif

            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div class="flex justify-between gap-3 border-b border-gray-100 py-1 dark:border-gray-700">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.observatory_corpus') }}</dt>
                    <dd class="text-right tabular-nums text-gray-900 dark:text-gray-100 text-xs" data-knowledge-corpus>
                        {{ trans_choice('ai.observatory_corpus_vectors', $summary['chunks'], ['count' => number_format($summary['chunks'])]) }}
                        · {{ __('ai.observatory_corpus_tokens', ['count' => number_format($summary['corpus_tokens'])]) }}
                        · {{ __('ai.observatory_corpus_characters', ['count' => number_format($summary['corpus_characters'])]) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-1 dark:border-gray-700">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_distinct_articles') }}</dt>
                    <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($diagnostics['distinct_articles']) }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-1 dark:border-gray-700">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_distinct_files') }}</dt>
                    <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($diagnostics['distinct_files']) }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-1 dark:border-gray-700">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_index_family') }}</dt>
                    <dd class="text-gray-900 dark:text-gray-100">{{ $diagnostics['index_family'] ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-1 dark:border-gray-700">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_index_model') }}</dt>
                    <dd class="text-xs text-gray-900 dark:text-gray-100">{{ $diagnostics['index_model'] ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-1 dark:border-gray-700">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_stored_providers') }}</dt>
                    <dd class="text-xs text-gray-900 dark:text-gray-100" data-rag-stored-providers>{{ $diagnostics['providers'] ? implode(', ', $diagnostics['providers']) : '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-b border-gray-100 py-1 dark:border-gray-700">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('ai.knowledge_console_stored_models') }}</dt>
                    <dd class="text-xs text-gray-900 dark:text-gray-100" data-rag-stored-models>{{ $diagnostics['models'] ? implode(', ', $diagnostics['models']) : '—' }}</dd>
                </div>
            </dl>

            <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('ai.knowledge_console_diagnostics_note') }}</p>
        </div>
    </details>
</div>
