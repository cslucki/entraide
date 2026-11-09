<x-admin-layout :title="__('admin.import_rss.historique.title')">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.historique.subtitle') }}</p>

        <a href="{{ route('admin.outils.import-rss.sources') }}"
           class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            {{ __('admin.import_rss.historique.retour') }}
        </a>
    </div>

    {{-- Filtres et recherche — CDC §4. --}}
    <div class="mb-6 grid gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5 dark:border-gray-700 dark:bg-gray-800">
        <div>
            <label for="sourceId" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.historique.filtre_source') }}</label>
            <select id="sourceId" wire:model.live="sourceId" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">{{ __('admin.import_rss.historique.filtre_source_toutes') }}</option>
                @foreach($sources as $source)
                    <option value="{{ $source->id }}">{{ $source->label ?? $source->url }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="organizationId" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.historique.filtre_organization') }}</label>
            <select id="organizationId" wire:model.live="organizationId" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">{{ __('admin.import_rss.historique.filtre_organization_toutes') }}</option>
                @foreach($organizations as $organization)
                    <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="statut" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.historique.filtre_statut') }}</label>
            <select id="statut" wire:model.live="statut" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">{{ __('admin.import_rss.historique.filtre_statut_tous') }}</option>
                <option value="non_importe">{{ __('admin.import_rss.fiche.statut_non_importe') }}</option>
                <option value="brouillon">{{ __('admin.import_rss.fiche.statut_brouillon') }}</option>
                <option value="publie">{{ __('admin.import_rss.fiche.statut_publie') }}</option>
                <option value="supprime">{{ __('admin.import_rss.supprime') }}</option>
            </select>
        </div>

        <div>
            <label for="dateDepuis" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.historique.filtre_date_depuis') }}</label>
            <input type="date" id="dateDepuis" wire:model.live="dateDepuis" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
        </div>

        <div>
            <label for="dateJusqua" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.historique.filtre_date_jusqua') }}</label>
            <input type="date" id="dateJusqua" wire:model.live="dateJusqua" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
        </div>

        <div class="sm:col-span-2 lg:col-span-5">
            <label for="recherche" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.historique.recherche_titre') }}</label>
            <input type="text" id="recherche" wire:model.live.debounce.400ms="recherche" placeholder="{{ __('admin.import_rss.historique.recherche_placeholder') }}"
                   class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
        </div>
    </div>

    @if($articles->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
            {{ __('admin.import_rss.historique.vide') }}
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3">{{ __('admin.import_rss.historique.colonne_titre') }}</th>
                        <th class="px-4 py-3">{{ __('admin.import_rss.historique.colonne_source') }}</th>
                        <th class="px-4 py-3">{{ __('admin.import_rss.historique.colonne_date') }}</th>
                        <th class="px-4 py-3">{{ __('admin.import_rss.historique.colonne_statut') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($articles as $article)
                        @php($statutsArticle = $statuts->get($article->source_identity, collect()))
                        <tr>
                            <td class="px-4 py-3 text-sm">
                                <a href="{{ route('admin.outils.import-rss.articles.show', $article) }}"
                                   class="font-medium text-gray-900 hover:text-indigo-600 dark:text-gray-100 dark:hover:text-indigo-400">
                                    {{ $article->titre }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                {{ $article->source->label ?? $article->source->url }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                {{ $article->source_published_at?->format('d/m/Y') }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @if($statutsArticle->isEmpty())
                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('admin.import_rss.fiche.statut_non_importe') }}</span>
                                @else
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($statutsArticle as $orgId => $info)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                {{ $organisationsParId[$orgId]->name ?? $orgId }} :
                                                @if($info['trashed'])
                                                    {{ __('admin.import_rss.supprime') }}
                                                @else
                                                    <a href="{{ route('admin.blog.edit', ['post' => $info['blog_post_id']]) }}" class="underline hover:no-underline">
                                                        {{ $info['statut'] === 'publie' ? __('admin.import_rss.fiche.statut_publie') : __('admin.import_rss.fiche.statut_brouillon') }}
                                                    </a>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $articles->links() }}
        </div>
    @endif
</x-admin-layout>
