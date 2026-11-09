<x-admin-layout :title="__('admin.import_rss.sources.title')">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.sources.subtitle') }}</p>

        <div class="flex shrink-0 flex-wrap gap-2">
            {{-- TASK-1792 — l'historique editorial, tous articles toutes sources. --}}
            <a href="{{ route('admin.outils.import-rss.historique') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                {{ __('admin.import_rss.sources.voir_historique') }}
            </a>

            {{-- Navigation reciproque avec l'ecran en lot : les deux parcours coexistent. --}}
            <a href="{{ route('admin.outils.import-rss') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v16m8-8H4"/></svg>
                {{ __('admin.import_rss.sources.go_to_batch') }}
            </a>
        </div>
    </div>

    {{-- TASK-1792 — bascule d'affichage des archivees, masquees par defaut. --}}
    <label class="mb-4 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
        <input type="checkbox" wire:model.live="afficherArchivees" class="rounded border-gray-300 text-indigo-600">
        {{ $afficherArchivees ? __('admin.import_rss.sources.masquer_archivees') : __('admin.import_rss.sources.afficher_archivees') }}
    </label>

    @if($sources->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
            {{ __('admin.import_rss.sources.vide') }}
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($sources as $source)
                    <li>
                        <a href="{{ route('admin.outils.import-rss.sources.show', $source) }}"
                           class="flex items-center justify-between gap-3 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-gray-700">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $source->label ?? $source->url }}
                                    @if($source->isDisabled())
                                        <span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ __('admin.import_rss.sources.desactivee_badge') }}</span>
                                    @endif
                                    @if($source->isArchived())
                                        <span class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('admin.import_rss.sources.archivee_badge') }}</span>
                                    @endif
                                </p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $source->url }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ trans_choice('admin.import_rss.sources.articles_count', $source->articles_count, ['nombre' => $source->articles_count]) }}</span>
                                <span>
                                    @if($source->last_fetched_at)
                                        {{ $source->last_fetched_at->diffForHumans() }}
                                    @else
                                        {{ __('admin.import_rss.sources.jamais_charge') }}
                                    @endif
                                </span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4">
            {{ $sources->links() }}
        </div>
    @endif
</x-admin-layout>
