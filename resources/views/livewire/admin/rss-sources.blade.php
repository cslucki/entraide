<x-admin-layout :title="__('admin.import_rss.sources.title')">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.sources.subtitle') }}</p>

        {{-- Navigation reciproque avec l'ecran en lot : les deux parcours coexistent. --}}
        <a href="{{ route('admin.outils.import-rss') }}"
           class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v16m8-8H4"/></svg>
            {{ __('admin.import_rss.sources.go_to_batch') }}
        </a>
    </div>

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
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $source->label ?? $source->url }}</p>
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
