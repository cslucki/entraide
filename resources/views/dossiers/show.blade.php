<x-app-layout>
    @php
        $orgParam = request()->route('organization');
        $entries = $dossier->dossierBlogPosts->filter(fn ($entry) => $entry->blogPost !== null)->values();
        $series = $series ?? null;
        $seriesRoot = $series?->rootBlogPost;
        $seriesAnnexes = $series?->items ?? collect();

        $ungrouped = collect();
        $seriesRootEntry = null;
        $annexBlogPostIds = $seriesAnnexes->pluck('blog_post_id')->toArray();

        foreach ($entries as $entry) {
            $bp = $entry->blogPost;
            if ($series && $series->root_blog_post_id === $bp->id) {
                $seriesRootEntry = $entry;
            } elseif (in_array($bp->id, $annexBlogPostIds)) {
                continue;
            } else {
                $ungrouped->push($entry);
            }
        }

        $canView = fn ($bp) => $canManageArticles || ($bp->status === 'published');
        $blogShowRoute = fn ($bp) => $bp && $canView($bp)
            ? route('organization.blog.show', ['organization' => $orgParam, 'post' => $bp->slug])
            : null;
        $blogEditRoute = fn ($bp) => $bp && $canManageArticles
            ? route('organization.blog.edit', ['organization' => $orgParam, 'post' => $bp->slug])
            : null;

        $entriesForJs = $entries->map(fn ($entry) => [
            'id' => $entry->getKey(),
            'position' => $entry->position,
            'blog_post_id' => $entry->blog_post_id,
            'blog_post' => [
                'id' => $entry->blogPost->id,
                'title' => $entry->blogPost->title,
                'slug' => $entry->blogPost->slug,
                'status' => $entry->blogPost->status,
                'user_id' => $entry->blogPost->user_id,
                'updated_at' => $entry->blogPost->updated_at?->toIso8601String(),
                'published_at' => $entry->blogPost->published_at?->toIso8601String(),
                'author' => $entry->blogPost->user ? [
                    'first_name' => $entry->blogPost->user->first_name,
                    'name' => $entry->blogPost->user->name,
                ] : null,
                'coAuthors' => $entry->blogPost->coAuthors->map(fn ($u) => [
                    'id' => $u->id,
                    'first_name' => $u->first_name,
                    'name' => $u->name,
                ])->toArray(),
                'canView' => $canView($entry->blogPost),
                'canEdit' => $canManageArticles,
                'viewUrl' => $blogShowRoute($entry->blogPost),
                'editUrl' => $blogEditRoute($entry->blogPost),
            ],
        ])->values();

        $seriesData = $series ? [
            'id' => $series->getKey(),
            'root_blog_post_id' => $series->root_blog_post_id,
            'root' => $seriesRoot ? [
                'id' => $seriesRoot->id,
                'title' => $seriesRoot->title,
                'slug' => $seriesRoot->slug,
                'status' => $seriesRoot->status,
                'user_id' => $seriesRoot->user_id,
                'updated_at' => $seriesRoot->updated_at?->toIso8601String(),
                'published_at' => $seriesRoot->published_at?->toIso8601String(),
                'author' => $seriesRoot->user ? [
                    'first_name' => $seriesRoot->user->first_name,
                    'name' => $seriesRoot->user->name,
                ] : null,
                'coAuthors' => $seriesRoot->coAuthors->map(fn ($u) => [
                    'id' => $u->id,
                    'first_name' => $u->first_name,
                    'name' => $u->name,
                ])->toArray(),
                'canView' => $canView($seriesRoot),
                'canEdit' => $canManageArticles,
                'viewUrl' => $blogShowRoute($seriesRoot),
                'editUrl' => $blogEditRoute($seriesRoot),
            ] : null,
            'items' => $seriesAnnexes->map(fn ($item) => [
                'id' => $item->getKey(),
                'blog_post_id' => $item->blog_post_id,
                'position' => $item->position,
                'blog_post' => $item->blogPost ? [
                    'id' => $item->blogPost->id,
                    'title' => $item->blogPost->title,
                    'slug' => $item->blogPost->slug,
                    'status' => $item->blogPost->status,
                    'user_id' => $item->blogPost->user_id,
                    'updated_at' => $item->blogPost->updated_at?->toIso8601String(),
                    'published_at' => $item->blogPost->published_at?->toIso8601String(),
                    'author' => $item->blogPost->user ? [
                        'first_name' => $item->blogPost->user->first_name,
                        'name' => $item->blogPost->user->name,
                    ] : null,
                    'coAuthors' => $item->blogPost->coAuthors->map(fn ($u) => [
                        'id' => $u->id,
                        'first_name' => $u->first_name,
                        'name' => $u->name,
                    ])->toArray(),
                    'canView' => $canView($item->blogPost),
                    'canEdit' => $canManageArticles,
                    'viewUrl' => $blogShowRoute($item->blogPost),
                    'editUrl' => $blogEditRoute($item->blogPost),
                ] : null,
            ])->values(),
        ] : null;

        $seriesEligibleForJs = $seriesEligibleArticles->map(fn ($article) => [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'status' => $article->status,
        ])->values();
    @endphp

    <x-slot name="title">{{ $dossier->name }} — {{ __('dossiers.title') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <a href="{{ route('organization.dossiers.index', ['organization' => $orgParam]) }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('dossiers.back') }}</a>
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
                <a href="{{ route('organization.dossiers.edit', ['organization' => $orgParam, 'dossier' => $dossier->getKey()]) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
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

        {{-- Tabs --}}
        <div x-data="dossierTabs('{{ request()->get('tab', 'contenus') }}')" @hashchange.window="onHashChange()">
            <div class="mt-8 border-b border-gray-200 dark:border-gray-700" role="tablist" aria-label="{{ __('dossiers.contents_tab') }}">
                <button @click="activate('contenus')" :aria-selected="active === 'contenus'" :tabindex="active === 'contenus' ? '0' : '-1'" role="tab" id="tab-contenus" aria-controls="tabpanel-contenus" class="inline-flex px-4 py-3 text-sm font-semibold border-b-2 -mb-px transition-colors" :class="active === 'contenus' ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'">
                    {{ __('dossiers.contents_tab') }}
                </button>
                <button @click="activate('fichiers')" :aria-selected="active === 'fichiers'" :tabindex="active === 'fichiers' ? '0' : '-1'" role="tab" id="tab-fichiers" aria-controls="tabpanel-fichiers" class="inline-flex px-4 py-3 text-sm font-semibold border-b-2 -mb-px transition-colors" :class="active === 'fichiers' ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'">
                    {{ __('dossiers.files_tab') }}
                </button>
                <button @click="activate('membres')" :aria-selected="active === 'membres'" :tabindex="active === 'membres' ? '0' : '-1'" role="tab" id="tab-membres" aria-controls="tabpanel-membres" class="inline-flex px-4 py-3 text-sm font-semibold border-b-2 -mb-px transition-colors" :class="active === 'membres' ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'">
                    {{ __('dossiers.members_tab') }}
                </button>
            </div>

            {{-- Tab: Contents --}}
            <div x-show="active === 'contenus'" x-cloak role="tabpanel" id="tabpanel-contenus" aria-labelledby="tab-contenus" class="mt-6">
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6"
                         x-data="dossierContentsCard(@js([
                             'csrfToken' => csrf_token(),
                             'dossierId' => $dossier->getKey(),
                             'orgParam' => $orgParam,
                             'currentUserId' => auth()->id(),
                             'canManageArticles' => $canManageArticles,
                             'series' => $seriesData,
                             'ungrouped' => $entriesForJs->filter(fn ($e) => !$seriesData || ($e['blog_post_id'] !== $seriesData['root_blog_post_id'] && !in_array($e['blog_post_id'], $annexBlogPostIds)))->values(),
                             'seriesEligibleArticles' => $seriesEligibleForJs,
                             'i18n' => [
                                 'seriesTitle' => __('dossiers.content_series_title'),
                                 'ungroupedTitle' => __('dossiers.content_ungrouped_title'),
                                 'rootBadge' => __('dossiers.content_root_badge'),
                                 'annexBadge' => __('dossiers.content_annex_badge'),
                                 'ungroupedBadge' => __('dossiers.content_ungrouped_badge'),
                                 'rootRole' => __('dossiers.content_root_role'),
                                 'ungroupedRole' => __('dossiers.content_ungrouped_role'),
                                 'noSeries' => __('dossiers.content_no_series'),
                                 'noSeriesHelp' => __('dossiers.content_no_series_help'),
                                 'setRoot' => __('dossiers.content_set_root'),
                                 'addToSeries' => __('dossiers.content_add_to_series'),
                                 'removeFromSeries' => __('dossiers.content_remove_from_series'),
                                 'changeRoot' => __('dossiers.content_change_root'),
                                 'deleteSeries' => __('dossiers.content_delete_series'),
                                 'seriesDeleteModalTitle' => __('dossiers.content_series_delete_modal_title'),
                                 'seriesDeleteModalBody' => __('dossiers.content_series_delete_modal_body'),
                                 'detachModalTitle' => __('dossiers.content_detach_modal_title'),
                                 'detachModalBody' => __('dossiers.content_detach_modal_body'),
                                 'viewArticle' => __('dossiers.content_view_article'),
                                 'editArticle' => __('dossiers.edit_article'),
                                 'removeFromFolder' => __('dossiers.remove_from_folder'),
                                 'cancel' => __('dossiers.cancel'),
                                 'moveUp' => __('dossiers.move_up'),
                                 'moveDown' => __('dossiers.move_down'),
                                 'seriesCreated' => __('dossiers.series_created'),
                                 'seriesDeleted' => __('dossiers.series_deleted'),
                                 'annexAdded' => __('dossiers.annex_added'),
                                 'annexRemoved' => __('dossiers.annex_removed'),
                                 'seriesRootUpdated' => __('dossiers.series_root_updated'),
                                 'statusDraft' => __('dossiers.status_draft'),
                                 'statusPublished' => __('dossiers.status_published'),
                                 'articleDetached' => __('dossiers.article_detached'),
                                 'dragHandle' => __('dossiers.content_drag_handle'),
                                 'attachArticle' => __('dossiers.attach_article'),
                                 'byAuthor' => __('dossiers.content_by_author'),
                                 'withCoauthors' => __('dossiers.content_with_coauthors'),
                             ],
                         ]))">
                    <div class="flex flex-col gap-4">
                        {{-- Header --}}
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.contents_tab') }}</h2>
                            <template x-if="canManageArticles">
                                <button @click="openAddArticleModal()" type="button" class="w-full whitespace-nowrap rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:w-auto">{{ __('dossiers.add_article') }}</button>
                            </template>
                        </div>
                        {{-- Search row --}}
                        <input x-model="searchQuery" type="text" placeholder="{{ __('dossiers.article_search_placeholder') }}" class="w-full rounded-lg border-gray-300 text-sm shadow-sm sm:max-w-md dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">

                    <template x-if="message">
                        <div class="mt-4 rounded-xl border px-4 py-3 text-sm font-medium"
                             :class="messageType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200'"
                             x-text="message"></div>
                    </template>

                    @if($canUseSemanticArticleSearch)
                    <section class="mt-6 rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm dark:border-indigo-900/50 dark:bg-gray-800 sm:p-6"
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

                    <template x-if="!hasSeries && filteredUngrouped.length === 0">
                        <div class="mt-6 rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.noSeries"></h3>
                            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-300" x-text="i18n.noSeriesHelp"></p>
                        </div>
                    </template>

                    <template x-if="hasSeries">
                        <div class="mt-6 space-y-4">
                            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 dark:border-indigo-900/60 dark:bg-indigo-950/30">
                                <div class="p-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-5 w-5 text-indigo-500" viewBox="0 0 20 20" fill="currentColor"><path d="M10.362 1.093a.75.75 0 00-.724 0L2.523 5.018 10 9.143l7.477-4.125-7.115-3.925zM18 6.443l-7.25 4v8.25l6.862-3.786A.75.75 0 0018 14.25V6.443zm-8.75 12.25v-8.25l-7.25-4v7.807a.75.75 0 00.388.657l6.862 3.786z"/></svg>
                                            <span class="text-sm font-semibold text-indigo-700 dark:text-indigo-300" x-text="i18n.seriesTitle"></span>
                                        </div>
                                        <template x-if="canManageArticles">
                                            <div class="relative" data-article-menu>
                                                <button @click="showSeriesMenu = !showSeriesMenu" type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700">
                                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                                </button>
                                                <div x-show="showSeriesMenu" @click.away="showSeriesMenu = false" x-cloak x-transition class="absolute right-0 z-20 mt-1 w-52 rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                                    <button @click="showSeriesMenu = false" type="button" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.changeRoot"></button>
                                                    <button @click="showSeriesMenu = false; openDeleteSeriesModal()" type="button" class="w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" x-text="i18n.deleteSeries"></button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                {{-- Root article --}}
                                <template x-if="seriesRoot">
                                    <div class="border-t border-indigo-200 dark:border-indigo-900/60">
                                        <div class="px-4 py-3">
                                            <div class="flex items-start justify-between gap-3 rounded-xl bg-white px-3 py-3 dark:bg-gray-800 sm:py-2" data-no-drag :data-article-id="seriesRoot.blogPostId">
                                                <div class="flex items-start gap-2 min-w-0 flex-1">
                                                    {{-- No drag handle for root --}}
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/60 dark:text-indigo-300" x-text="i18n.rootBadge"></span>
                                                            <span class="rounded-full px-1.5 py-0.5 text-xs font-semibold"
                                                                  :class="seriesRoot.status === 'published' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200' : (seriesRoot.status === 'archived' ? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200')"
                                                                  x-text="formatStatus(seriesRoot.status)"></span>
                                                        </div>
                                                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                                            <template x-if="seriesRoot.canView && seriesRoot.viewUrl">
                                                                <a :href="seriesRoot.viewUrl" class="hover:underline" x-text="seriesRoot.title"></a>
                                                            </template>
                                                            <template x-if="!seriesRoot.canView || !seriesRoot.viewUrl">
                                                                <span x-text="seriesRoot.title"></span>
                                                            </template>
                                                        </p>
                                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                            <template x-if="seriesRoot.author">
                                                                <span x-text="i18n.byAuthor.replace(':name', (seriesRoot.author.first_name || '') + ' ' + (seriesRoot.author.name || ''))"></span>
                                                            </template>
                                                            <template x-if="seriesRoot.coAuthors && seriesRoot.coAuthors.length > 0">
                                                                <span> · <span x-text="i18n.withCoauthors.replace(':names', seriesRoot.coAuthors.map(c => (c.first_name || '') + ' ' + (c.name || '')).join(', '))"></span></span>
                                                            </template>
                                                            <template x-if="seriesRoot.updatedAt">
                                                                <span> · <span x-text="formatDate(seriesRoot.updatedAt)"></span></span>
                                                            </template>
                                                            <template x-if="seriesRoot.publishedAt">
                                                                <span> · <span x-text="'📅 ' + formatDate(seriesRoot.publishedAt)"></span></span>
                                                            </template>
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="flex shrink-0 items-center gap-1">
                                                    <template x-if="seriesRoot.canView && seriesRoot.viewUrl">
                                                        <a :href="seriesRoot.viewUrl" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" x-text="i18n.viewArticle"></a>
                                                    </template>
                                                    <template x-if="seriesRoot.canEdit && seriesRoot.editUrl">
                                                        <a :href="seriesRoot.editUrl" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" x-text="i18n.editArticle"></a>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Annexes --}}
                                <div class="border-t border-indigo-200 px-4 py-3 dark:border-indigo-900/60" x-show="seriesItems.length > 0">
                                    <div class="space-y-2" x-ref="annexesContainer">
                                        <template x-for="(item, index) in filteredAnnexItems" :key="item.id">
                                            <div :data-article-id="item.blog_post_id" class="flex items-start justify-between gap-3 rounded-xl bg-white px-3 py-3 dark:bg-gray-800 sm:py-2">
                                                <div class="flex items-start gap-2 min-w-0 flex-1">
                                                    <template x-if="canManageArticles">
                                                        <span class="drag-handle mt-0.5 cursor-grab shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" :title="i18n.dragHandle">
                                                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                                        </span>
                                                    </template>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-center gap-1.5">
                                                            <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300" x-text="i18n.annexBadge"></span>
                                                            <span class="rounded-full px-1.5 py-0.5 text-xs font-semibold"
                                                                  :class="item.blog_post?.status === 'published' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200' : (item.blog_post?.status === 'archived' ? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200')"
                                                                  x-text="formatStatus(item.blog_post?.status)"></span>
                                                        </div>
                                                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                                            <template x-if="item.blog_post?.canView && item.blog_post?.viewUrl">
                                                                <a :href="item.blog_post.viewUrl" class="hover:underline" x-text="item.blog_post?.title || '—'"></a>
                                                            </template>
                                                            <template x-if="!item.blog_post?.canView || !item.blog_post?.viewUrl">
                                                                <span x-text="item.blog_post?.title || '—'"></span>
                                                            </template>
                                                        </p>
                                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                            <template x-if="item.blog_post?.author">
                                                                <span x-text="i18n.byAuthor.replace(':name', (item.blog_post.author.first_name || '') + ' ' + (item.blog_post.author.name || ''))"></span>
                                                            </template>
                                                            <template x-if="item.blog_post?.coAuthors && item.blog_post.coAuthors.length > 0">
                                                                <span> · <span x-text="i18n.withCoauthors.replace(':names', item.blog_post.coAuthors.map(c => (c.first_name || '') + ' ' + (c.name || '')).join(', '))"></span></span>
                                                            </template>
                                                            <template x-if="item.blog_post?.updatedAt">
                                                                <span> · <span x-text="formatDate(item.blog_post.updatedAt)"></span></span>
                                                            </template>
                                                            <template x-if="item.blog_post?.publishedAt">
                                                                <span> · <span x-text="'📅 ' + formatDate(item.blog_post.publishedAt)"></span></span>
                                                            </template>
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="flex shrink-0 items-center gap-1">
                                                    <template x-if="canManageArticles">
                                                        <div class="flex items-center gap-0.5">
                                                            <button @click="moveAnnex(index, -1)" :disabled="index === 0" :title="i18n.moveUp" type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700">
                                                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd"/></svg>
                                                            </button>
                                                            <button @click="moveAnnex(index, 1)" :disabled="index === filteredAnnexItems.length - 1" :title="i18n.moveDown" type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700">
                                                                <svg class="h-3.5 w-3.5 rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd"/></svg>
                                                            </button>
                                                        </div>
                                                    </template>
                                                    <template x-if="item.blog_post?.canView && item.blog_post?.viewUrl">
                                                        <a :href="item.blog_post.viewUrl" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" x-text="i18n.viewArticle"></a>
                                                    </template>
                                                    <template x-if="item.blog_post?.canEdit && item.blog_post?.editUrl">
                                                        <a :href="item.blog_post.editUrl" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" x-text="i18n.editArticle"></a>
                                                    </template>
                                                    <template x-if="canManageArticles">
                                                        <div class="relative" data-article-menu>
                                                            <button @click="toggleMenu(item.id)" type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700">
                                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                                            </button>
                                                            <div x-show="openMenuId === item.id" @click.away="openMenuId = null" x-cloak x-transition class="absolute right-0 z-20 mt-1 w-52 rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                                                <button @click="removeAnnex(item)" type="button" class="w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" x-text="i18n.removeFromSeries"></button>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Ungrouped articles --}}
                    <template x-if="filteredUngrouped.length > 0 || (hasSeries && seriesItems.length === 0)">
                        <div class="mt-6">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3" x-text="i18n.ungroupedTitle"></h3>
                            <div class="space-y-2" x-ref="ungroupedContainer">
                                <template x-for="(entry, index) in filteredUngrouped" :key="entry.id">
                                    <div :data-article-id="entry.blog_post_id" class="flex items-start justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-3 dark:border-gray-700 dark:bg-gray-900/40 sm:py-2">
                                        <div class="flex items-start gap-2 min-w-0 flex-1">
                                            <template x-if="canManageArticles">
                                                <span class="drag-handle mt-0.5 cursor-grab shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" :title="i18n.dragHandle">
                                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                                </span>
                                            </template>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300" x-text="i18n.ungroupedBadge"></span>
                                                    <span class="rounded-full px-1.5 py-0.5 text-xs font-semibold"
                                                          :class="entry.blog_post?.status === 'published' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200' : (entry.blog_post?.status === 'archived' ? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200')"
                                                          x-text="formatStatus(entry.blog_post?.status)"></span>
                                                </div>
                                                <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                                    <template x-if="entry.blog_post?.canView && entry.blog_post?.viewUrl">
                                                        <a :href="entry.blog_post.viewUrl" class="hover:underline" x-text="entry.blog_post?.title || '—'"></a>
                                                    </template>
                                                    <template x-if="!entry.blog_post?.canView || !entry.blog_post?.viewUrl">
                                                        <span x-text="entry.blog_post?.title || '—'"></span>
                                                    </template>
                                                </p>
                                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                    <template x-if="entry.blog_post?.author">
                                                        <span x-text="i18n.byAuthor.replace(':name', (entry.blog_post.author.first_name || '') + ' ' + (entry.blog_post.author.name || ''))"></span>
                                                    </template>
                                                    <template x-if="entry.blog_post?.coAuthors && entry.blog_post.coAuthors.length > 0">
                                                        <span> · <span x-text="i18n.withCoauthors.replace(':names', entry.blog_post.coAuthors.map(c => (c.first_name || '') + ' ' + (c.name || '')).join(', '))"></span></span>
                                                    </template>
                                                    <template x-if="entry.blog_post?.updatedAt">
                                                        <span> · <span x-text="formatDate(entry.blog_post.updatedAt)"></span></span>
                                                    </template>
                                                    <template x-if="entry.blog_post?.publishedAt">
                                                        <span> · <span x-text="'📅 ' + formatDate(entry.blog_post.publishedAt)"></span></span>
                                                    </template>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-1">
                                            <template x-if="canManageArticles">
                                                <div class="flex items-center gap-0.5">
                                                    <button @click="moveUngrouped(index, -1)" :disabled="index === 0" :title="i18n.moveUp" type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700">
                                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd"/></svg>
                                                    </button>
                                                    <button @click="moveUngrouped(index, 1)" :disabled="index === filteredUngrouped.length - 1" :title="i18n.moveDown" type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700">
                                                        <svg class="h-3.5 w-3.5 rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd"/></svg>
                                                    </button>
                                                </div>
                                            </template>
                                            <template x-if="entry.blog_post?.canView && entry.blog_post?.viewUrl">
                                                <a :href="entry.blog_post.viewUrl" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" x-text="i18n.viewArticle"></a>
                                            </template>
                                            <template x-if="entry.blog_post?.canEdit && entry.blog_post?.editUrl">
                                                <a :href="entry.blog_post.editUrl" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" x-text="i18n.editArticle"></a>
                                            </template>
                                            <template x-if="canManageArticles">
                                                <div class="relative" data-article-menu>
                                                    <button @click="toggleMenu(entry.id)" type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700">
                                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0zm6 0a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                                    </button>
                                                    <div x-show="openMenuId === entry.id" @click.away="openMenuId = null" x-cloak x-transition class="absolute right-0 z-20 mt-1 w-52 rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                                        <template x-if="hasSeries">
                                                            <button @click="addToSeries(entry)" type="button" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.addToSeries"></button>
                                                        </template>
                                                        <template x-if="!hasSeries && canManageArticles">
                                                            <button @click="setAsRoot(entry)" type="button" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.setRoot"></button>
                                                        </template>
                                                        <button @click="confirmDetach(entry)" type="button" class="w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" x-text="i18n.removeFromFolder"></button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- Empty state --}}
                    <template x-if="!hasSeries && filteredUngrouped.length === 0">
                        <div class="mt-6 rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-700">
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.articles_empty_body') }}</p>
                        </div>
                    </template>

                    {{-- Add Article Modal --}}
                    <template x-if="showAddModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeAddModal()">
                            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.add_article_title') }}</h3>
                                    <button @click="closeAddModal()" type="button" class="rounded-lg p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                                    </button>
                                </div>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.article_search_help') }}</p>
                                <input x-ref="addSearchInput" x-model="addSearchQuery" @input.debounce.300ms="searchEligibleArticles()" type="text" placeholder="{{ __('dossiers.article_search_placeholder') }}" class="mt-4 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                <div class="mt-4 max-h-64 space-y-2 overflow-y-auto">
                                    <template x-if="addSearching">
                                        <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">...</p>
                                    </template>
                                    <template x-for="article in addSearchResults" :key="article.id">
                                        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/40">
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="article.title"></p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="article.statusLabel"></p>
                                            </div>
                                            <button @click="attachArticle(article)" :disabled="adding" class="ml-3 whitespace-nowrap rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50" x-text="i18n.attachArticle"></button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Delete Series Modal --}}
                    <template x-if="showDeleteSeriesModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showDeleteSeriesModal = false">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.seriesDeleteModalTitle"></h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300" x-text="i18n.seriesDeleteModalBody"></p>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button @click="showDeleteSeriesModal = false" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.cancel"></button>
                                    <button @click="deleteSeries()" :disabled="saving" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50" x-text="i18n.deleteSeries"></button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Detach Modal --}}
                    <template x-if="showDetachModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showDetachModal = false; detachEntry = null">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.detachModalTitle"></h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300" x-text="i18n.detachModalBody"></p>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button @click="showDetachModal = false; detachEntry = null" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.cancel"></button>
                                    <button @click="detachArticle()" :disabled="detaching" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50" x-text="i18n.removeFromFolder"></button>
                                </div>
                            </div>
                        </div>
                    </template>
                </section>
            </div>

            {{-- Tab: Files --}}
            <div x-show="active === 'fichiers'" x-cloak role="tabpanel" id="tabpanel-fichiers" aria-labelledby="tab-fichiers" class="mt-6">
                @if($canViewFiles)
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6"
                         x-data="dossierFilesCard(@js([
                             'csrfToken' => csrf_token(),
                             'dossierId' => $dossier->getKey(),
                             'orgParam' => $orgParam,
                             'canManageFiles' => $canManageFiles,
                             'canDeleteFiles' => $canDeleteFiles,
                             'activeTab' => 'fichiers',
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

                    @if($canManageFiles)
                    <div class="mt-5">
                        <div id="dossier-file-pond" x-ref="filePondContainer"></div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.file_upload_help') }}</p>
                    </div>
                    @endif

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
                                    <a :href="'{{ route('organization.dossiers.files.show', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', file.id)"
                                       class="inline-flex items-center justify-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                                       x-text="i18n.download"></a>
                                    @if($canDeleteFiles)
                                    <button @click="confirmDeleteFile(file)" :disabled="saving"
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

            {{-- Tab: Members --}}
            <div x-show="active === 'membres'" x-cloak role="tabpanel" id="tabpanel-membres" aria-labelledby="tab-membres" class="mt-6">
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6"
                         x-data="dossierMembersCard(@js([
                             'csrfToken' => csrf_token(),
                             'dossierId' => $dossier->getKey(),
                             'orgParam' => $orgParam,
                             'ownerId' => $dossier->owner_id,
                             'currentUserId' => auth()->id(),
                             'canManage' => $canManageMembers,
                             'activeTab' => 'membres',
                             'i18n' => [
                                 'confirmRemove' => __('dossiers.confirm_remove_member'),
                                 'memberAdded' => __('dossiers.member_added'),
                                 'memberRoleUpdated' => __('dossiers.member_role_updated'),
                                 'memberRemoved' => __('dossiers.member_removed'),
                                 'memberAlready' => __('dossiers.member_already'),
                                 'roleReader' => __('dossiers.role_reader'),
                                 'roleEditor' => __('dossiers.role_editor'),
                             ],
                         ]))">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.members_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.members_help') }}</p>

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
                                        <template x-if="m.isYou">
                                            <span class="mt-0.5 inline-block rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/60 dark:text-indigo-300" x-text="m.roleLabel || m.role"></span>
                                        </template>
                                    </div>
                                </div>
                                <template x-if="canManage && !m.isYou">
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
                                </template>
                            </div>
                        </template>

                        <template x-if="members.length === 0">
                            <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">{{ __('dossiers.no_members') }}</p>
                        </template>
                    </div>

                    @if($canManageMembers)
                        <div class="mt-5">
                            <button @click="showSearch = !showSearch" class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                {{ __('dossiers.add_member') }}
                            </button>
                        </div>

                        <div x-show="showSearch" x-transition class="mt-4 space-y-3">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.add_member_help') }}</p>
                            <input type="text" x-model="searchQuery" @input.debounce.300ms="searchUsers()" placeholder="{{ __('dossiers.member_search_placeholder') }}"
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
                                                <span>{{ __('dossiers.add_member') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <button @click="showSearch = false; searchQuery = ''; searchResults = []" class="text-xs text-gray-500 hover:underline dark:text-gray-400">{{ __('dossiers.cancel') }}</button>
                        </div>
                    @endif
                </section>
            </div>
        </div>

        {{-- No-JS fallback --}}
        <noscript>
            <div class="mt-8 space-y-8">
                <section id="contenus" class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.contents_tab') }}</h2>
                </section>
                <section id="fichiers" class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.files_tab') }}</h2>
                </section>
                <section id="membres" class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.members_tab') }}</h2>
                </section>
            </div>
        </noscript>
    </x-page-container>
</x-app-layout>
