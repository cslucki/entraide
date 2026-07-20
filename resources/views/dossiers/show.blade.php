<x-app-layout>
    @php
        $organizationRouteParam = request()->route('organization');
        $entries = $dossier->dossierBlogPosts->filter(fn ($entry) => $entry->blogPost !== null)->values();
        $seriesAnnexesForJs = $series?->items->map(fn ($item) => ['id' => $item->getKey(), 'blog_post_id' => $item->blog_post_id, 'title' => $item->blogPost->title ?? '—', 'slug' => $item->blogPost->slug ?? null, 'position' => $item->position])->values() ?? collect();
        $seriesEligibleArticlesForJs = $seriesEligibleArticles->map(fn ($article) => ['id' => $article->id, 'title' => $article->title, 'slug' => $article->slug, 'status' => $article->status])->values();
    @endphp

    <x-slot name="title">{{ $dossier->name }} — {{ __('dossiers.title') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <a href="{{ route('organization.dossiers.index', ['organization' => $organizationRouteParam]) }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('dossiers.back') }}</a>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $dossier->name }}</h1>
                    @if($userRole === 'owner')
                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-200">{{ __('dossiers.private_badge') }}</span>
                    @else
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-950/60 dark:text-amber-200">{{ __('dossiers.shared_badge') }}</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('dossiers.your_role', ['role' => __('dossiers.role_'.$userRole)]) }}</span>
                    @endif
                </div>
                <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.show_subtitle') }}</p>
            </div>
            @if($userRole === 'owner')
                <a href="{{ route('organization.dossiers.edit', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('dossiers.rename') }}
                </a>
            @endif
        </div>

        @if(session('success'))
            <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="flex flex-col gap-6">
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.articles_title') }}</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.articles_help') }}</p>
                        </div>
                    </div>

                    @if($entries->isEmpty())
                        <div class="mt-6 rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.articles_empty_title') }}</h3>
                            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.articles_empty_body') }}</p>
                        </div>
                    @else
                        <ol class="mt-6 space-y-3">
                            @foreach($entries as $index => $entry)
                                @php $post = $entry->blogPost; @endphp
                                <li class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white text-xs font-bold text-gray-600 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">{{ $index + 1 }}</span>
                                                @if($series && $series->root_blog_post_id === $post->id)
                                                    <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-200">{{ __('dossiers.series_card_root') }}</span>
                                                @elseif($series && $series->items->contains('blog_post_id', $post->id))
                                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/60 dark:text-amber-200">{{ __('dossiers.series_card_annex') }}</span>
                                                @endif
                                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $post->status === 'published' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200' }}">
                                                    {{ __('dossiers.status_'.$post->status, [], app()->getLocale()) !== 'dossiers.status_'.$post->status ? __('dossiers.status_'.$post->status) : $post->status }}
                                                </span>
                                            </div>
                                            <h3 class="mt-3 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $post->title }}</h3>
                                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.article_updated_at', ['date' => $post->updated_at->diffForHumans()]) }}</p>
                                        </div>

                                        @if($canManageArticles)
                                            <div class="flex flex-col gap-2 sm:flex-row lg:flex-col">
                                                <a href="{{ route('organization.blog.edit', ['organization' => $organizationRouteParam, 'post' => $post->slug]) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                                    {{ __('dossiers.edit_article') }}
                                                </a>
                                                <form method="POST" action="{{ route('organization.dossiers.articles.destroy', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey(), 'post' => $post->getKey()]) }}" onsubmit="return confirm('{{ __('dossiers.confirm_detach_article', ['title' => $post->title]) }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30">
                                                        {{ __('dossiers.detach_article') }}
                                                    </button>
                                                </form>
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        @if($canManageArticles && $entries->count() > 1)
                            <form method="POST" action="{{ route('organization.dossiers.articles.reorder', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="mt-6 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                                @csrf
                                @method('PATCH')
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.reorder_title') }}</h3>
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ __('dossiers.reorder_help') }}</p>
                                <div class="mt-4 space-y-2">
                                    @foreach($entries as $entry)
                                        <label class="grid gap-2 sm:grid-cols-[4rem_minmax(0,1fr)] sm:items-center">
                                            <span class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ __('dossiers.position') }}</span>
                                            <select name="articles[]" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                                @foreach($entries as $option)
                                                    <option value="{{ $option->blog_post_id }}" @selected($option->blog_post_id === $entry->blog_post_id)>{{ $option->blogPost->title }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endforeach
                                </div>
                                <x-primary-button class="mt-4">{{ __('dossiers.save_order') }}</x-primary-button>
                            </form>
                        @endif
                    @endif
                </section>

                @if($canUseSemanticArticleSearch)
                    <section class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm dark:border-indigo-900/50 dark:bg-gray-800 sm:p-6"
                             x-data="dossierSemanticArticleSearch(@js([
                                 'endpoint' => route('organization.dossiers.semantic-search', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]),
                                 'i18n' => [
                                     'validationTooShort' => __('dossiers.semantic_search_validation_too_short'),
                                     'unavailable' => __('dossiers.semantic_search_unavailable'),
                                     'genericError' => __('dossiers.semantic_search_generic_error'),
                                     'passage' => __('dossiers.semantic_search_passage'),
                                     'resultsCount' => __('dossiers.semantic_search_results_count'),
                                 ],
                             ]))"
                             :aria-busy="loading ? 'true' : 'false'">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">{{ __('dossiers.semantic_search_label') }}</p>
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.semantic_search_title') }}</h2>
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.semantic_search_help') }}</p>
                        </div>

                        <form class="mt-5 flex flex-col gap-3 sm:flex-row" @submit.prevent="search">
                            <label class="sr-only" for="dossier-semantic-search-query">{{ __('dossiers.semantic_search_label') }}</label>
                            <input id="dossier-semantic-search-query"
                                   type="search"
                                   x-model="query"
                                   minlength="2"
                                   maxlength="500"
                                   autocomplete="off"
                                   placeholder="{{ __('dossiers.semantic_search_placeholder') }}"
                                   class="block w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500 sm:flex-1">
                            <button type="submit"
                                    class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-offset-gray-800"
                                    :disabled="loading">
                                <span x-show="!loading">{{ __('dossiers.semantic_search_button') }}</span>
                                <span x-show="loading" x-cloak>{{ __('dossiers.semantic_search_loading') }}</span>
                            </button>
                        </form>

                        <div class="mt-4" aria-live="polite">
                            <p x-show="validationError" x-cloak class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200" x-text="validationError"></p>
                            <p x-show="error" x-cloak class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200" x-text="error"></p>
                            <p x-show="loading" x-cloak class="text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.semantic_search_loading') }}</p>
                        </div>

                        <div class="mt-5" x-show="searched && !loading && !validationError && !error" x-cloak aria-live="polite">
                            <template x-if="results.length > 0">
                                <div>
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.semantic_search_results_title') }}</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-300" x-text="resultCountLabel()"></p>
                                    </div>

                                    <ol class="mt-3 space-y-3">
                                        <template x-for="result in results.slice(0, 5)" :key="`${result.slug}-${result.chunk_index}`">
                                            <li class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div class="min-w-0">
                                                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300" x-text="passageLabel(result.chunk_index)"></p>
                                                        <h4 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100" x-text="result.title"></h4>
                                                        <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-300" x-text="excerpt(result.content)"></p>
                                                    </div>
                                                    <a :href="result.citation_url" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-800">
                                                        {{ __('dossiers.semantic_search_read_article') }}
                                                    </a>
                                                </div>
                                            </li>
                                        </template>
                                    </ol>
                                </div>
                            </template>

                            <p x-show="results.length === 0" class="rounded-2xl border border-dashed border-gray-300 px-5 py-8 text-center text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                                {{ __('dossiers.semantic_search_no_results') }}
                            </p>
                        </div>
                    </section>
                @endif

                {{-- Series Section --}}
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6"
                         x-data="dossierSeriesCard(@js([
                             'csrfToken' => csrf_token(),
                             'dossierId' => $dossier->getKey(),
                             'orgParam' => $organizationRouteParam,
                             'hasSeries' => (bool) $series,
                             'seriesId' => $series?->getKey(),
                             'rootPostId' => $series?->root_blog_post_id,
                             'rootPostTitle' => $series?->rootBlogPost?->title ?? '',
                             'rootSlug' => $series?->rootBlogPost?->slug,
                             'annexes' => $seriesAnnexesForJs,
                             'eligibleArticles' => $seriesEligibleArticlesForJs,
                             'i18n' => [
                                 'emptyTitle' => __('dossiers.series_empty_title'),
                                 'emptyBody' => __('dossiers.series_empty_body'),
                                 'createBtn' => __('dossiers.series_create'),
                                 'rootLabel' => __('dossiers.series_root_label'),
                                 'annexesLabel' => __('dossiers.series_annexes'),
                                 'annexesEmpty' => __('dossiers.series_annexes_empty'),
                                 'addAnnex' => __('dossiers.series_annex_add'),
                                 'addAnnexHelp' => __('dossiers.series_annex_add_help'),
                                 'annexSelect' => __('dossiers.series_annex_select'),
                                 'deleteSeries' => __('dossiers.series_delete'),
                                 'deleteConfirm' => __('dossiers.series_delete_confirm'),
                                 'seriesCreated' => __('dossiers.series_created'),
                                 'rootSet' => __('dossiers.series_root_set'),
                                 'annexAdded' => __('dossiers.annex_added'),
                                 'annexRemoved' => __('dossiers.annex_removed'),
                                 'seriesDeleted' => __('dossiers.series_deleted'),
                                 'noAnnexesToAdd' => __('dossiers.series_no_annexes_to_add'),
                                 'editArticle' => __('dossiers.edit_article'),
                             ],
                         ]))">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.series_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.series_empty_body') }}</p>

                    <div x-show="message" x-transition
                         :class="messageType === 'error' ? 'bg-red-50 border-red-200 text-red-800 dark:bg-red-950/40 dark:border-red-900/60 dark:text-red-200' : 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-900/60 dark:text-emerald-200'"
                         class="mt-4 rounded-xl border px-4 py-3 text-sm font-medium">
                        <span x-text="message"></span>
                    </div>

                    {{-- No series state --}}
                    <div x-show="!hasSeries" class="mt-6">
                        <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.emptyTitle"></h3>
                            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-300" x-text="i18n.emptyBody"></p>
                            @if($canManageArticles && $entries->isNotEmpty())
                                <div class="mt-4">
                                    <select x-model="newRootPostId" class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        <option value="">{{ __('dossiers.series_root_label') }}</option>
                                        @foreach($entries as $entry)
                                            <option value="{{ $entry->blog_post_id }}">{{ $entry->blogPost->title }}</option>
                                        @endforeach
                                    </select>
                                    <button @click="createSeries()" :disabled="!newRootPostId || saving"
                                            class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                                        <span x-show="!saving" x-text="i18n.createBtn"></span>
                                        <span x-show="saving">...</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Series exists --}}
                    <div x-show="hasSeries" class="mt-6 space-y-5">
                        {{-- Root article --}}
                        <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/30">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400" x-text="i18n.rootLabel"></span>
                                    <h4 class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="rootPostTitle"></h4>
                                </div>
                                @if($canManageArticles)
                                    <div class="flex items-center gap-2">
                                        <a :href="'{{ route('organization.blog.edit', ['organization' => $organizationRouteParam, 'post' => '__SLUG__']) }}'.replace('__SLUG__', rootSlug)" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" x-text="i18n.editArticle" x-show="rootSlug"></a>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Annexes --}}
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.annexesLabel"></h3>
                            <template x-if="annexes.length === 0">
                                <p class="mt-2 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300" x-text="i18n.annexesEmpty"></p>
                            </template>
                            <div class="mt-3 space-y-2">
                                <template x-for="(annex, idx) in annexes" :key="annex.id">
                                    <div class="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-900/40">
                                        <div class="flex items-center gap-3">
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white text-xs font-bold text-gray-600 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700" x-text="idx + 1"></span>
                                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="annex.title"></span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <a :href="'{{ route('organization.blog.edit', ['organization' => $organizationRouteParam, 'post' => '__SLUG__']) }}'.replace('__SLUG__', annex.slug)" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" x-text="i18n.editArticle" x-show="annex.slug"></a>
                                            <button @click="removeAnnex(annex)" class="inline-flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/30 dark:hover:text-red-400">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Add annex --}}
                        @if($canManageArticles)
                            <div x-show="eligibleArticles.length > 0">
                                <button @click="showAddAnnex = !showAddAnnex" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                    <span x-text="i18n.addAnnex"></span>
                                </button>
                                <div x-show="showAddAnnex" x-transition class="mt-3 space-y-3">
                                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="i18n.addAnnexHelp"></p>
                                    <select x-model="annexToAdding" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        <option value="">{{ __('dossiers.series_annex_select') }}</option>
                                        <template x-for="a in eligibleArticles" :key="a.id">
                                            <option :value="a.id" x-text="a.title"></option>
                                        </template>
                                    </select>
                                    <button @click="addAnnex()" :disabled="!annexToAdding || saving"
                                            class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                                        <span x-text="i18n.addAnnex"></span>
                                    </button>
                                </div>
                            </div>
                        @endif

                        {{-- Delete series --}}
                        @if($canManageArticles)
                            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                                <button @click="if(confirm(i18n.deleteConfirm)) deleteSeries()"
                                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-red-600 hover:underline dark:text-red-400">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    <span x-text="i18n.deleteSeries"></span>
                                </button>
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Files Section --}}
                @if($canViewFiles)
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6"
                         x-data="dossierFilesCard(@js([
                             'csrfToken' => csrf_token(),
                             'dossierId' => $dossier->getKey(),
                             'orgParam' => $organizationRouteParam,
                             'canManageFiles' => $canManageFiles,
                             'canDeleteFiles' => $canDeleteFiles,
                             'i18n' => [
                                 'title' => __('dossiers.files_title'),
                                 'emptyTitle' => __('dossiers.files_empty_title'),
                                 'emptyBody' => __('dossiers.files_empty_body'),
                                 'uploadHelp' => __('dossiers.file_upload_help'),
                                 'uploaded' => __('dossiers.file_uploaded'),
                                 'uploadFailed' => __('dossiers.file_upload_failed'),
                                 'deleted' => __('dossiers.file_deleted'),
                                 'deleteFailed' => __('dossiers.file_upload_failed'),
                                 'confirmDelete' => __('dossiers.file_confirm_delete'),
                                 'download' => __('dossiers.file_download'),
                                 'deleteFile' => __('dossiers.file_delete'),
                                 'name' => __('dossiers.file_name'),
                                 'size' => __('dossiers.file_size'),
                                 'uploadedBy' => __('dossiers.file_uploaded_by'),
                                 'storageUnlimited' => __('dossiers.storage_unlimited'),
                                 'storageUsedLabel' => __('dossiers.storage_used'),
                             ],
                         ]))">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.files_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.files_empty_body') }}</p>

                    <div x-show="message" x-transition
                         :class="messageType === 'error' ? 'bg-red-50 border-red-200 text-red-800 dark:bg-red-950/40 dark:border-red-900/60 dark:text-red-200' : 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-900/60 dark:text-emerald-200'"
                         class="mt-4 rounded-xl border px-4 py-3 text-sm font-medium">
                        <span x-text="message"></span>
                    </div>

                    {{-- Upload form --}}
                    @if($canManageFiles)
                    <div class="mt-5">
                        <div id="dossier-file-pond" x-ref="filePondContainer"></div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.file_upload_help') }}</p>
                    </div>
                    @endif

                    {{-- Quota bar --}}
                    <div class="mt-5" x-show="totalFiles > 0 || quota.used_bytes > 0">
                        <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                            <span x-text="quotaLabel"></span>
                        </div>
                        <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" x-show="quota.limit_bytes !== null">
                            <div class="h-full rounded-full transition-all duration-300"
                                 :class="quotaPercent > 90 ? 'bg-red-500' : (quotaPercent > 70 ? 'bg-amber-500' : 'bg-indigo-500')"
                                 :style="'width:' + quotaPercent + '%'"></div>
                        </div>
                    </div>

                    {{-- File list --}}
                    <div class="mt-5 space-y-3">
                        <template x-for="file in files" :key="file.id">
                            <div class="flex flex-col gap-3 rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-900/40 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate" x-text="file.display_name || file.original_name"></span>
                                        <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300" x-text="file.mime_type"></span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                        <span x-text="file.sizeFormatted"></span>
                                        <span x-show="file.uploader" x-text="'{{ __('dossiers.file_uploaded_by') }}: ' + (file.uploader?.name || file.uploader?.email || '')"></span>
                                        <span x-show="file.uploadedAtFormatted" x-text="file.uploadedAtFormatted"></span>
                                    </div>
                                </div>
                                <div class="flex w-full flex-col gap-2 shrink-0 sm:ml-4 sm:w-auto sm:flex-row sm:items-center">
                                    <a :href="'{{ route('organization.dossiers.files.show', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', file.id)"
                                       class="inline-flex items-center justify-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                                       x-text="i18n.download"></a>
                                    @if($canDeleteFiles)
                                    <button @click="deleteFile(file)" :disabled="saving"
                                            class="inline-flex items-center justify-center gap-1 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30 disabled:opacity-50">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        <span x-text="i18n.deleteFile"></span>
                                    </button>
                                    @endif
                                </div>
                            </div>
                        </template>

                        <template x-if="files.length === 0 && totalFiles === 0">
                            <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.emptyTitle"></h3>
                                <p class="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-300" x-text="i18n.emptyBody"></p>
                            </div>
                        </template>
                    </div>

                    {{-- Pagination --}}
                    <div class="mt-4 flex items-center justify-center gap-2" x-show="lastPage > 1">
                        <button @click="loadFiles(currentPage - 1)" :disabled="currentPage <= 1"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">&laquo;</button>
                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="currentPage + ' / ' + lastPage"></span>
                        <button @click="loadFiles(currentPage + 1)" :disabled="currentPage >= lastPage"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">&raquo;</button>
                    </div>
                </section>
                @endif
            </div>

            <div class="flex flex-col gap-6">
                <aside class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.attach_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.attach_help') }}</p>

                    @if($canManageArticles)
                        @if($eligibleArticles->isEmpty())
                            <p class="mt-5 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">{{ __('dossiers.no_eligible_articles') }}</p>
                        @else
                            <form method="POST" action="{{ route('organization.dossiers.articles.store', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="mt-5 space-y-4">
                                @csrf
                                <div>
                                    <x-input-label for="blog_post_id" :value="__('dossiers.article_select_label')" />
                                    <select id="blog_post_id" name="blog_post_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        @foreach($eligibleArticles as $article)
                                            <option value="{{ $article->id }}">{{ $article->title }} — {{ __('dossiers.status_'.$article->status, [], app()->getLocale()) !== 'dossiers.status_'.$article->status ? __('dossiers.status_'.$article->status) : $article->status }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error :messages="$errors->get('blog_post_id')" class="mt-2" />
                                </div>
                                <x-primary-button>{{ __('dossiers.attach_article') }}</x-primary-button>
                            </form>
                        @endif
                    @else
                        <p class="mt-5 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">{{ __('dossiers.articles_attach_disabled') }}</p>
                    @endif
                </aside>

                @if($canManageMembers)
                    <aside class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6"
                           x-data="dossierMembersCard(@js([
                               'csrfToken' => csrf_token(),
                               'dossierId' => $dossier->getKey(),
                               'orgParam' => $organizationRouteParam,
                               'ownerId' => $dossier->owner_id,
                               'currentUserId' => auth()->id(),
                               'i18n' => [
                                   'confirmRemove' => __('dossiers.confirm_remove_member'),
                                   'memberAdded' => __('dossiers.member_added'),
                                   'memberRoleUpdated' => __('dossiers.member_role_updated'),
                                   'memberRemoved' => __('dossiers.member_removed'),
                                   'memberAlready' => __('dossiers.member_already'),
                               ],
                           ]))">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.members_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.members_help') }}</p>

                        @if(session('success'))
                            <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                                {{ session('success') }}
                            </div>
                        @endif

                        <div x-show="message" x-transition
                             :class="messageType === 'error' ? 'bg-red-50 border-red-200 text-red-800 dark:bg-red-950/40 dark:border-red-900/60 dark:text-red-200' : 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-900/60 dark:text-emerald-200'"
                             class="mt-3 rounded-xl border px-4 py-3 text-sm font-medium">
                            <span x-text="message"></span>
                        </div>

                        <div class="mt-5 space-y-3">
                            <template x-for="m in members" :key="m.id">
                                <div class="flex flex-col gap-3 rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-900/40 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300" x-text="m.initial"></div>
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="m.displayName"></div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400" x-text="m.email"></div>
                                        </div>
                                    </div>
                                    <div class="flex w-full flex-col gap-2 sm:ml-4 sm:w-auto sm:flex-row sm:items-center sm:gap-2">
                                        <select :value="m.role" @change="updateRole(m, $event.target.value)"
                                                class="w-full rounded-lg border-gray-300 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 sm:w-auto">
                                            <option value="reader">{{ __('dossiers.role_reader') }}</option>
                                            <option value="editor">{{ __('dossiers.role_editor') }}</option>
                                        </select>
                                        <button @click="removeMember(m)"
                                                class="inline-flex h-8 w-full items-center justify-center gap-1 rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/30 dark:hover:text-red-400 sm:w-8"
                                                title="{{ __('dossiers.member_removed') }}">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="members.length === 0">
                                <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">{{ __('dossiers.no_members') }}</p>
                            </template>
                        </div>

                        <div class="mt-5">
                            <button @click="showSearch = !showSearch" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                {{ __('dossiers.add_member') }}
                            </button>
                        </div>

                        <div x-show="showSearch" x-transition class="mt-4 space-y-3">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.add_member_help') }}</p>
                            <input type="text" x-model="searchQuery" @input.debounce.300ms="searchUsers()" :placeholder="i18n.memberAdded ? '{{ __('dossiers.member_search_placeholder') }}' : ''"
                                   class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                            <div x-show="searchLoading" class="text-xs text-gray-400">...</div>
                            <div class="space-y-2">
                                <template x-for="u in searchResults" :key="u.id">
                                    <div class="flex flex-col gap-3 rounded-xl bg-gray-50 px-3 py-3 dark:bg-gray-900/40 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex items-center gap-2">
                                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300" x-text="(u.first_name || u.name || '?').charAt(0)"></div>
                                            <div>
                                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="u.displayName"></span>
                                                <span class="ml-1 text-xs text-gray-500 dark:text-gray-400" x-text="u.email"></span>
                                            </div>
                                        </div>
                                        <div class="flex w-full flex-col gap-2 sm:ml-4 sm:w-auto sm:flex-row sm:items-center sm:gap-2">
                                            <select x-model="u._selectedRole" class="w-full rounded-lg border-gray-300 text-xs shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 sm:w-auto">
                                                <option value="reader">{{ __('dossiers.role_reader') }}</option>
                                                <option value="editor">{{ __('dossiers.role_editor') }}</option>
                                            </select>
                                            <button @click="addMember(u)" class="inline-flex w-full items-center justify-center gap-1 rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 sm:w-auto">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                                <span x-text="i18n.memberAdded ? '{{ __('dossiers.add_member') }}' : ''"></span>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <button @click="showSearch = false; searchQuery = ''; searchResults = []" class="text-xs text-gray-500 hover:underline dark:text-gray-400">{{ __('dossiers.cancel') }}</button>
                        </div>
                    </aside>
                @endif
            </div>
        </div>
    </x-page-container>
</x-app-layout>
