<x-admin-layout :title="$rssSource->label ?? $rssSource->url">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $rssSource->label ?? $rssSource->url }}</p>
            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $rssSource->url }}</p>
        </div>

        <a href="{{ route('admin.outils.import-rss.sources') }}"
           class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            {{ __('admin.import_rss.sources.retour') }}
        </a>
    </div>

    @if($articles->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
            {{ __('admin.import_rss.sources.aucun_article') }}
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($articles as $article)
                    <li>
                        <a href="{{ route('admin.outils.import-rss.articles.show', $article) }}"
                           class="flex items-center justify-between gap-3 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-gray-700">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $article->titre }}</p>
                                <p class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                    @if($article->source_published_at)<span>{{ $article->source_published_at->format('d/m/Y') }}</span>@endif
                                    @if($article->auteur)<span>{{ $article->auteur }}</span>@endif
                                </p>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4">
            {{ $articles->links() }}
        </div>
    @endif
</x-admin-layout>
