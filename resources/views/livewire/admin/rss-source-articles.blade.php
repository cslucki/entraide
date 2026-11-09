<x-admin-layout :title="$rssSource->label ?? $rssSource->url">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $rssSource->label ?? $rssSource->url }}
                @if($rssSource->isDisabled())
                    <span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ __('admin.import_rss.sources.desactivee_badge') }}</span>
                @endif
                @if($rssSource->isArchived())
                    <span class="ml-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('admin.import_rss.sources.archivee_badge') }}</span>
                @endif
            </p>
            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $rssSource->url }}</p>
        </div>

        <a href="{{ route('admin.outils.import-rss.sources') }}"
           class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            {{ __('admin.import_rss.sources.retour') }}
        </a>
    </div>

    {{-- TASK-1792 — gestion de la source : renommer, actualiser, desactiver,
         archiver. Jamais de suppression physique. --}}
    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        @if($message)
            <div class="mb-3 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ $message }}</div>
        @endif
        @if($erreur)
            <div class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ $erreur }}</div>
        @endif

        <form wire:submit.prevent="renommer" class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="label" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.sources.renommer_label') }}</label>
                <input type="text" id="label" wire:model="label" placeholder="{{ __('admin.import_rss.sources.renommer_placeholder') }}"
                       class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <button type="submit" class="shrink-0 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                {{ __('admin.import_rss.sources.renommer_enregistrer') }}
            </button>
        </form>

        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
            <button type="button" wire:click="actualiser" @disabled($rssSource->isDisabled())
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('admin.import_rss.sources.actualiser') }}
            </button>
            <button type="button" wire:click="basculerActivation"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                {{ $rssSource->isDisabled() ? __('admin.import_rss.sources.reactiver') : __('admin.import_rss.sources.desactiver') }}
            </button>
            <button type="button" wire:click="basculerArchivage"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                {{ $rssSource->isArchived() ? __('admin.import_rss.sources.desarchiver') : __('admin.import_rss.sources.archiver') }}
            </button>
        </div>
        @if($rssSource->isDisabled())
            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">{{ __('admin.import_rss.sources.desactivee_aide') }}</p>
        @endif
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
