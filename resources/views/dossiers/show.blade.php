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
        $publicIdentity = fn ($user) => $user?->isDisplayableIn(currentOrganization()) ? [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'name' => $user->name,
        ] : [
            'id' => null,
            'first_name' => null,
            'name' => __('profile.deactivated_user'),
        ];
        $ownerDisplayable = $dossier->owner?->isDisplayableIn(currentOrganization()) ?? false;

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
                'author' => $entry->blogPost->user ? $publicIdentity($entry->blogPost->user) : null,
                'coAuthors' => $entry->blogPost->coAuthors->map(fn ($u) => $publicIdentity($u))->toArray(),
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
                'author' => $seriesRoot->user ? $publicIdentity($seriesRoot->user) : null,
                'coAuthors' => $seriesRoot->coAuthors->map(fn ($u) => $publicIdentity($u))->toArray(),
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
                    'author' => $item->blogPost->user ? $publicIdentity($item->blogPost->user) : null,
                    'coAuthors' => $item->blogPost->coAuthors->map(fn ($u) => $publicIdentity($u))->toArray(),
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
                    @if($dossier->isLoopDossier())
                        {{-- Dossier racine : ni « Privé » ni « Partagé » — il est
                             à sa Boucle, et le rôle affiché en dérive. --}}
                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-200">{{ __('dossiers.loop_dossier_badge') }}</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('dossiers.your_role', ['role' => __('dossiers.role_'.$userRole)]) }}</span>
                    @elseif($userRole === 'owner')
                        <a href="{{ route('organization.dossiers.edit', ['organization' => $orgParam, 'dossier' => $dossier->getKey()]) }}" class="inline-flex items-center justify-center rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200" title="{{ __('dossiers.rename') }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </a>
                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-200">{{ __('dossiers.private_badge') }}</span>
                    @else
                        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-950/60 dark:text-amber-200">{{ __('dossiers.shared_badge') }}</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('dossiers.your_role', ['role' => __('dossiers.role_'.$userRole)]) }}</span>
                    @endif
                </div>
                <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.drive_subtitle') }}</p>
            </div>
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

        {{-- ── Le Drive : une seule surface (TASK-1130, passe 2) ── --}}
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
                                  'uploading' => __('dossiers.file_uploading'),
                                  'uploadingFile' => __('dossiers.file_uploading_file'),
                                  'uploadProgress' => __('dossiers.file_upload_progress'),
                                  'uploaded' => __('dossiers.file_uploaded'),
                                  'uploadFailed' => __('dossiers.file_upload_failed'),
                                  'deleted' => __('dossiers.file_deleted'),
                                  'deleteFailed' => __('dossiers.file_delete_failed'),
                                  'confirmDeleteTitle' => __('dossiers.file_confirm_delete_title'),
                                  'confirmDeleteBody' => __('dossiers.file_confirm_delete_body'),
                                  'confirmDeleteCancel' => __('dossiers.file_confirm_delete_cancel'),
                                  'confirmDeleteConfirm' => __('dossiers.file_confirm_delete_confirm'),
                                  'download' => __('dossiers.file_download'),
                                  'preview' => __('dossiers.file_preview'),
                                  'deleteFile' => __('dossiers.file_delete'),
                                  'name' => __('dossiers.file_name'),
                                  'size' => __('dossiers.file_size'),
                                  'uploadedBy' => __('dossiers.file_uploaded_by'),
                                  'storageUnlimited' => __('dossiers.storage_unlimited'),
                                  'storageUsedLabel' => __('dossiers.storage_used'),
                                   'articleCreated' => __('dossiers.article_created'),
                                   'articleCreateFailed' => __('dossiers.article_create_failed'),
                                   'markdownCreated' => __('dossiers.markdown_created'),
                                   'markdownCreateFailed' => __('dossiers.markdown_create_failed'),
                                   'filesUploaded' => __('dossiers.files_uploaded'),
                                   'filesBatchResult' => __('dossiers.files_batch_result'),
                                   'filesBatchErrors' => __('dossiers.files_batch_errors'),
                                   'fileTooLarge' => __('dossiers.file_too_large'),
                                  'networkError' => __('dossiers.network_error'),
                                  'duplicateName' => __('dossiers.file_duplicate_name'),
                                  'previewNotAvailable' => __('dossiers.file_preview_not_available'),
                                  'searchPlaceholder' => __('dossiers.file_search_placeholder'),
                              ],
                         ]))">
                    @if($canManageFiles)
                    {{-- Depot par glisser : la surcouche s'allume au survol d'un
                         vrai fichier, et le depot part dans l'upload existant —
                         le meme que les entrees du menu, aucun second moteur. --}}
                    <div x-data="{ survol: 0 }"
                         @dragenter.prevent="if (($event.dataTransfer?.types || []).includes('Files')) survol++"
                         @dragover.prevent
                         @dragleave.prevent="survol = Math.max(0, survol - 1)"
                         @drop.prevent="survol = 0; if ($event.dataTransfer?.files?.length) handleMediaFiles({ target: { files: $event.dataTransfer.files, value: '' } }, 'drop')"
                         class="relative">
                        <div x-show="survol > 0" x-cloak
                             class="pointer-events-none absolute inset-0 z-40 flex items-center justify-center rounded-2xl border-2 border-dashed border-indigo-400 bg-indigo-50/90 dark:border-indigo-500 dark:bg-indigo-950/80">
                            <p class="text-base font-semibold text-indigo-700 dark:text-indigo-200">{{ __('dossiers.drive_drop_here') }}</p>
                        </div>
                    @endif

                    {{-- La barre du Drive : ou l'on est, chercher, creer. --}}
                    <div class="flex flex-wrap items-center gap-3">
                        <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 text-sm" aria-label="Breadcrumb">
                            @if($driveRoot)
                                <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $driveRoot->getKey()]) }}"
                                   class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ __('dossiers.drive_breadcrumb_root') }}</a>
                                <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                <span class="max-w-[14rem] truncate font-semibold text-gray-900 dark:text-gray-100" aria-current="page">{{ $dossier->name }}</span>
                            @else
                                <span class="font-semibold text-gray-900 dark:text-gray-100" aria-current="page">{{ $dossier->isLoopDossier() ? __('dossiers.drive_breadcrumb_root') : $dossier->name }}</span>
                            @endif
                        </nav>

                        <div class="relative w-full sm:w-64">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <svg class="h-4 w-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                            </div>
                            <input x-model="searchQuery" @input.debounce.300ms="onSearchInput()" type="search"
                                   class="block w-full rounded-xl border border-gray-300 bg-white py-2 pl-10 pr-3 text-sm text-gray-900 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-400"
                                   :placeholder="i18n.searchPlaceholder || 'Search files…'">
                        </div>

                        @if($canManageFiles)
                            <div class="relative shrink-0">
                        <button x-ref="fabButton" @click="showImportMenu = !showImportMenu" type="button" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                            {{ __('dossiers.fab_action') }}
                        </button>
                        <div x-show="showImportMenu" @click.away="showImportMenu = false" x-cloak x-transition class="absolute left-0 z-20 mt-2 w-64 rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            {{-- Section: Ajouter --}}
                            <div class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('dossiers.fab_section_add') }}</div>
                            <button @click="showImportMenu = false; browseFiles()" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                {{ __('dossiers.fab_add_file') }}
                            </button>
                            <button @click="showImportMenu = false; triggerMediaUpload('image')" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                {{ __('dossiers.fab_add_photo') }}
                            </button>
                            <button @click="showImportMenu = false; triggerMediaUpload('video')" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                {{ __('dossiers.fab_add_video') }}
                            </button>
                            <button @click="showImportMenu = false; triggerMediaUpload('audio')" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-pink-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
                                {{ __('dossiers.fab_add_audio') }}
                            </button>

                            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>

                            {{-- Section: Créer --}}
                            <div class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('dossiers.fab_section_create') }}</div>
                            <button @click="showImportMenu = false; openArticleModal()" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                {{ __('dossiers.fab_new_article') }}
                            </button>
                            <button @click="showImportMenu = false; openMdModal()" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                {{ __('dossiers.fab_markdown_note') }}
                            </button>

                            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>

                            {{-- Section: Dossier — l'entree « bientot » de la
                                 premiere heure, enfin cablee (TASK-1130). --}}
                            @if($dossier->isLoopDossier() && auth()->user()?->can('create', App\Models\Dossier::class))
                                <button @click="showImportMenu = false; window.dispatchEvent(new CustomEvent('open-new-folder'))" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                                    <svg class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                    {{ __('dossiers.fab_folder') }}
                                </button>
                            @endif
                        </div>
                            </div>
                        @endif
                    </div>

                    <div x-show="message" x-transition
                         :class="messageType === 'error' ? 'bg-red-50 border-red-200 text-red-800 dark:bg-red-950/40 dark:border-red-900/60 dark:text-red-200' : 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-900/60 dark:text-emerald-200'"
                         class="mt-4 rounded-xl border px-4 py-3 text-sm font-medium">
                        <span x-text="message"></span>
                    </div>

                    @if($canManageFiles)
                    <div class="mt-5 relative">
                        <div id="dossier-file-pond" x-ref="filePondContainer" class="hidden"></div>
                        {{-- Hidden file inputs for media types --}}
                        <input type="file" x-ref="imageInput" accept="image/*" capture="user" class="hidden" @change="handleMediaFiles($event, 'image')">
                        <input type="file" x-ref="videoInput" accept="video/*" capture="user" class="hidden" @change="handleMediaFiles($event, 'video')">
                        <input type="file" x-ref="audioInput" accept="audio/*" multiple class="hidden" @change="handleMediaFiles($event, 'audio')">
                    </div>
                    @endif

                    <div x-show="uploading" x-cloak x-transition class="mt-5 overflow-hidden rounded-2xl border border-indigo-200 bg-indigo-50/80 p-4 shadow-sm dark:border-indigo-900/60 dark:bg-indigo-950/30" role="status" aria-live="polite">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm shadow-indigo-900/20">
                                <svg class="h-5 w-5 animate-pulse" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01.88-7.903A5 5 0 0117.9 9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-sm font-semibold text-indigo-950 dark:text-indigo-100" x-text="uploadFileName ? i18n.uploadingFile.replace(':name', uploadFileName) : i18n.uploading"></p>
                                <p class="shrink-0 text-xs font-bold tabular-nums text-indigo-700 dark:text-indigo-200" x-text="i18n.uploadProgress.replace(':percent', uploadProgress)"></p>
                            </div>
                            <p class="mt-1 text-xs font-medium text-indigo-600/80 dark:text-indigo-300/80" x-show="uploadBatchTotal > 1" x-text="uploadBatchCurrent + ' / ' + uploadBatchTotal + ' ' + i18n.filesUploaded.toLowerCase()"></p>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/80 ring-1 ring-indigo-100 dark:bg-indigo-950 dark:ring-indigo-800">
                                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 via-sky-500 to-emerald-400 transition-all duration-200" :style="'width: ' + uploadProgress + '%'" aria-hidden="true"></div>
                                </div>
                                <p class="mt-2 text-xs text-indigo-700/80 dark:text-indigo-200/80" x-text="i18n.uploadHelp"></p>
                            </div>
                        </div>
                    </div>

                    {{-- Article Creation Modal --}}
                    <template x-if="showArticleModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showArticleModal = false" role="dialog" aria-modal="true" aria-labelledby="create-article-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="create-article-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.modal_new_article_title') }}</h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.modal_new_article_desc') }}</p>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('dossiers.modal_article_title_label') }}</label>
                                    <input type="text" x-model="articleTitle" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" placeholder="{{ __('dossiers.modal_article_title_placeholder') }}">
                                </div>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('dossiers.modal_article_category_label') }}</label>
                                    <select x-model="articleCategoryId" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                        <option value="">{{ __('dossiers.modal_article_category_placeholder') }}</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->displayName() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button @click="showArticleModal = false" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                        {{ __('dossiers.modal_cancel') }}
                                    </button>
                                    <button @click="createArticle()" :disabled="!articleTitle.trim() || !articleCategoryId" type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50">
                                        {{ __('dossiers.modal_create') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Markdown Note Modal --}}
                    <template x-if="showMdModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showMdModal = false" role="dialog" aria-modal="true" aria-labelledby="markdown-note-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="markdown-note-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.modal_new_markdown_title') }}</h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.modal_new_markdown_desc') }}</p>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('dossiers.modal_markdown_name_label') }}</label>
                                    <div class="mt-1 flex items-center gap-2">
                                        <input type="text" x-model="mdFileName" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" placeholder="{{ __('dossiers.modal_markdown_name_placeholder') }}">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">.md</span>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('dossiers.modal_markdown_content_label') }}</label>
                                    <textarea x-model="mdContent" rows="8" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" placeholder="{{ __('dossiers.modal_markdown_content_placeholder') }}"></textarea>
                                </div>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button @click="showMdModal = false" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                        {{ __('dossiers.modal_cancel') }}
                                    </button>
                                    <button @click="createMarkdownNote()" :disabled="!mdFileName.trim()" type="button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50">
                                        {{ __('dossiers.modal_create') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>




                    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700" x-show="totalFiles > 0 || {{ ($driveFolders->count() + $dossier->dossierBlogPosts->count()) > 0 ? 'true' : 'false' }}">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/60">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                        <button @click="toggleSort('name')" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-gray-100">
                                            {{ __('dossiers.file_name') }}
                                            <svg x-show="sortBy === 'name'" :class="sortDirection === 'asc' ? 'rotate-180' : ''" class="h-3 w-3 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </th>
                                    <th scope="col" class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 sm:table-cell dark:text-gray-300">
                                        <span class="inline-flex items-center gap-1">
                                            {{ __('dossiers.file_uploaded_by') }}
                                        </span>
                                    </th>
                                    <th scope="col" class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 sm:table-cell dark:text-gray-300">
                                        <button @click="toggleSort('size')" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-gray-100">
                                            {{ __('dossiers.file_size') }}
                                            <svg x-show="sortBy === 'size'" :class="sortDirection === 'asc' ? 'rotate-180' : ''" class="h-3 w-3 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </th>
                                    <th scope="col" class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 sm:table-cell dark:text-gray-300">
                                        <button @click="toggleSort('date')" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-gray-100">
                                            {{ __('dossiers.file_date') }}
                                            <svg x-show="sortBy === 'date'" :class="sortDirection === 'asc' ? 'rotate-180' : ''" class="h-3 w-3 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                        {{ __('dossiers.file_actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                                {{-- Les dossiers d'abord, comme dans tout Drive :
                                     des Dossiers reellement partages avec la
                                     Boucle, jamais une hierarchie simulee. --}}
                                @foreach($driveFolders as $folder)
                                    <tr class="cursor-pointer hover:bg-amber-50/40 dark:hover:bg-amber-500/5"
                                        @click="window.location = '{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}'">
                                        <td class="px-4 py-3">
                                            <div class="flex min-w-0 items-center gap-3">
                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300" aria-hidden="true">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                                                </span>
                                                <div class="min-w-0">
                                                    <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="block max-w-[26rem] truncate text-sm font-medium text-gray-900 hover:text-amber-700 dark:text-gray-100 dark:hover:text-amber-300" @click.stop>{{ $folder->name }}</a>
                                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('dossiers.drive_folder_items', $folder->files_count + $folder->dossier_blog_posts_count, ['count' => $folder->files_count + $folder->dossier_blog_posts_count]) }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-700 sm:table-cell dark:text-gray-300">{{ $folder->owner?->publicDisplayName() ?? '—' }}</td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-500 sm:table-cell dark:text-gray-400">—</td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-700 sm:table-cell dark:text-gray-300">{{ $folder->created_at?->isoFormat('L') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <svg class="ml-auto h-4 w-4 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                        </td>
                                    </tr>
                                @endforeach

                                {{-- Puis les Articles : identite editoriale — le
                                     crayon, le titre, l'auteur. Une seule
                                     apparition dans la surface documentaire. --}}
                                @foreach($dossier->dossierBlogPosts as $entry)
                                    @php $post = $entry->blogPost; @endphp
                                    @continue(! $post || ! $canView($post))
                                    <tr class="hover:bg-rose-50/40 dark:hover:bg-rose-500/5">
                                        <td class="px-4 py-3">
                                            <div class="flex min-w-0 items-center gap-3">
                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300" aria-hidden="true">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                                                </span>
                                                <div class="min-w-0">
                                                    <a href="{{ $blogShowRoute($post) }}" class="block max-w-[26rem] truncate text-sm font-medium text-gray-900 hover:text-rose-700 dark:text-gray-100 dark:hover:text-rose-300">{{ $post->title }}</a>
                                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.drive_article_badge') }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-700 sm:table-cell dark:text-gray-300">{{ $post->user?->publicDisplayName() ?? '—' }}</td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-500 sm:table-cell dark:text-gray-400">—</td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-700 sm:table-cell dark:text-gray-300">{{ $post->updated_at?->isoFormat('L') }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                            <div class="relative inline-block" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                                <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                                        class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                        aria-label="{{ $post->title }}">
                                                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                                </button>
                                                <div x-show="open" x-cloak @click.outside="open = false"
                                                     class="absolute right-0 top-full z-30 mt-1 w-48 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                                    <a href="{{ $blogShowRoute($post) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_open') }}</a>
                                                    @if($canManageArticles)
                                                        <a href="{{ $blogEditRoute($post) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_edit_article') }}</a>
                                                        <form method="POST" action="{{ route('organization.dossiers.articles.destroy', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'post' => $post->id]) }}">
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.drive_remove_article') }}</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach

                                <template x-for="file in sortedFiles" :key="file.id">
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                                      :class="{
                                                          'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400': file.mime_type === 'application/pdf',
                                                          'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400': file.mime_type?.startsWith('image/'),
                                                          'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-400': file.mime_type === 'application/msword' || file.mime_type?.includes('wordprocessingml'),
                                                          'bg-green-100 text-green-600 dark:bg-green-900/40 dark:text-green-400': file.mime_type === 'text/csv' || file.mime_type === 'application/vnd.ms-excel' || file.mime_type?.includes('spreadsheetml'),
                                                          'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400': file.mime_type === 'text/plain',
                                                          'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400': file.mime_type === 'text/markdown',
                                                          'bg-orange-100 text-orange-600 dark:bg-orange-900/40 dark:text-orange-400': file.mime_type === 'application/zip' || file.mime_type === 'application/x-zip-compressed',
                                                      }">
                                                    <svg x-show="file.mime_type === 'application/pdf'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                                    <svg x-show="file.mime_type?.startsWith('image/')" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                    <svg x-show="file.mime_type === 'application/msword' || file.mime_type?.includes('wordprocessingml')" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                    <svg x-show="file.mime_type === 'text/csv' || file.mime_type === 'application/vnd.ms-excel' || file.mime_type?.includes('spreadsheetml')" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                    <svg x-show="file.mime_type === 'text/plain'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                    <svg x-show="file.mime_type === 'text/markdown'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                                                    <svg x-show="file.mime_type === 'application/zip' || file.mime_type === 'application/x-zip-compressed'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                                </span>
                                                <div class="min-w-0">
                                                    <div class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="file.display_name || file.original_name"></div>
                                                    <div class="text-xs text-gray-500 dark:text-gray-400" x-text="fileTypeLabel(file.mime_type)"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-700 sm:table-cell dark:text-gray-300">
                                            <span x-text="file.uploader?.name || '—'"></span>
                                        </td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-700 sm:table-cell dark:text-gray-300" x-text="file.sizeFormatted"></td>
                                        <td class="hidden whitespace-nowrap px-4 py-3 text-sm text-gray-700 sm:table-cell dark:text-gray-300" x-text="file.uploadedAtFormatted"></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                            <div class="flex items-center justify-end gap-2">
                                                @if($canViewFiles)
                                                <button @click="openPreview(file)"
                                                        x-show="file.mime_type?.startsWith('image/') || file.mime_type === 'application/pdf' || file.mime_type === 'text/plain' || file.mime_type === 'text/markdown'"
                                                        class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                        title="{{ __('dossiers.file_preview') }}">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                </button>
                                                @endif
                                                <a :href="'{{ route('organization.dossiers.files.show', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', file.id)"
                                                   class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                   title="{{ __('dossiers.file_download') }}">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                </a>
                                                @if($canDeleteFiles)
                                                <button @click="openDeleteModal(file)" :disabled="saving"
                                                        class="rounded-lg p-1.5 text-red-500 hover:bg-red-100 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-900/40 dark:hover:text-red-300 disabled:opacity-50"
                                                        title="{{ __('dossiers.file_delete') }}">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

@if($driveFolders->isEmpty() && $dossier->dossierBlogPosts->isEmpty())
                    <template x-if="files.length === 0 && totalFiles === 0">
                        <div class="mt-4 rounded-xl border border-dashed border-gray-300 px-5 py-6 text-center dark:border-gray-700">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.drive_empty_title') }}</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.drive_empty_desc') }}</p>
                        </div>
                    </template>
                    @endif

                    <p class="mt-2 text-right text-xs text-gray-400 dark:text-gray-500" x-show="quota.used_bytes > 0" x-text="quotaLabel"></p>

                    <div class="mt-4 flex items-center justify-center gap-2" x-show="lastPage > 1">
                        <button @click="loadFiles(currentPage - 1)" :disabled="currentPage <= 1"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">&laquo;</button>
                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="currentPage + ' / ' + lastPage"></span>
                        <button @click="loadFiles(currentPage + 1)" :disabled="currentPage >= lastPage"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">&raquo;</button>
                    </div>
                    @if($canManageFiles)
                    </div>
                    @endif

                    {{-- Delete Confirmation Modal --}}
                    <template x-if="showDeleteModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showDeleteModal = false; deleteTarget = null" role="dialog" aria-modal="true" aria-labelledby="delete-file-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="delete-file-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.confirmDeleteTitle"></h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300" x-text="i18n.confirmDeleteBody"></p>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button @click="showDeleteModal = false; deleteTarget = null" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.confirmDeleteCancel"></button>
                                    <button @click="confirmDeleteFile()" :disabled="saving" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50" x-text="i18n.confirmDeleteConfirm"></button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Preview Modal --}}
                    <template x-if="showPreviewModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="showPreviewModal = false; previewFile = null" role="dialog" aria-modal="true" aria-labelledby="preview-title">
                            <div class="relative max-h-[90vh] max-w-[90vw] overflow-auto rounded-2xl bg-white shadow-xl dark:bg-gray-800" @click.stop>
                                <button @click="showPreviewModal = false; previewFile = null" type="button" class="absolute right-2 top-2 z-10 rounded-full bg-black/50 p-1.5 text-white hover:bg-black/70" aria-label="{{ __('dossiers.file_preview_close') }}">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                                <h3 id="preview-title" class="sr-only" x-text="previewFile?.display_name || previewFile?.original_name || 'Preview'"></h3>
                                {{-- Image preview --}}
                                <template x-if="previewFile?.mime_type?.startsWith('image/')">
                                    <img :src="'{{ route('organization.dossiers.files.preview', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', previewFile?.id)"
                                         :alt="previewFile?.display_name || previewFile?.original_name"
                                         class="max-h-[85vh] max-w-[85vw] rounded-2xl object-contain" />
                                </template>
                                {{-- PDF preview --}}
                                <template x-if="previewFile?.mime_type === 'application/pdf'">
                                    <iframe :src="'{{ route('organization.dossiers.files.preview', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', previewFile?.id)"
                                            class="h-[85vh] w-[85vw] rounded-2xl border-0"></iframe>
                                </template>
                                {{-- Text / Markdown preview --}}
                                <template x-if="previewFile?.mime_type === 'text/plain' || previewFile?.mime_type === 'text/markdown'">
                                    <div class="p-6">
                                        <div class="mb-3 flex items-center gap-2">
                                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="previewFile?.display_name || previewFile?.original_name"></span>
                                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300" x-text="previewFile?.mime_type"></span>
                                        </div>
                                        <div x-ref="textContent" class="max-h-[75vh] overflow-auto whitespace-pre-wrap rounded-xl bg-gray-50 p-4 font-mono text-sm text-gray-800 dark:bg-gray-900 dark:text-gray-200" x-init="$nextTick(() => { if (previewFile) fetch('{{ route('organization.dossiers.files.preview', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', previewFile.id)).then(r => r.text()).then(t => $refs.textContent.textContent = t); })"></div>
                                    </div>
                                </template>
                                {{-- Other file types: no inline preview --}}
                                <template x-if="!previewFile?.mime_type?.startsWith('image/') && previewFile?.mime_type !== 'application/pdf' && previewFile?.mime_type !== 'text/plain' && previewFile?.mime_type !== 'text/markdown'">
                                    <div class="p-8 text-center">
                                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="i18n.previewNotAvailable || '{{ __('dossiers.file_preview_not_available') }}'"></p>
                                        <a :href="'{{ route('organization.dossiers.files.show', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', previewFile?.id)"
                                           class="mt-4 inline-flex items-center gap-1 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                                           x-text="i18n.download"></a>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </section>
                @endif

        {{-- Nouveau dossier — un petit formulaire, pas un moteur. Poste sur le
             store() existant, avec la meme regle de partage qu'update(). --}}
        @can('create', App\Models\Dossier::class)
            @if($dossier->isLoopDossier())
                <div x-data="{ open: false }" @open-new-folder.window="open = true" x-on:keydown.escape.window="open = false">
                    <template x-if="open">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="open = false" role="dialog" aria-modal="true" aria-labelledby="new-folder-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800">
                                <h3 id="new-folder-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.drive_new_folder_title') }}</h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.drive_new_folder_desc') }}</p>
                                <form method="POST" action="{{ route('organization.dossiers.store', ['organization' => $orgParam]) }}" class="mt-4">
                                    @csrf
                                    <input type="hidden" name="visibility" value="loop">
                                    <input type="hidden" name="shared_with_loop_id" value="{{ $dossier->loop_id }}">
                                    <input type="hidden" name="return_to_dossier" value="{{ $dossier->getKey() }}">
                                    <label for="new-folder-name" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('dossiers.drive_new_folder_name') }}</label>
                                    <input id="new-folder-name" name="name" type="text" required maxlength="120"
                                           class="mt-1.5 w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                    <div class="mt-5 flex justify-end gap-2">
                                        <button type="button" @click="open = false" class="inline-flex min-h-11 items-center rounded-xl border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.drive_cancel') }}</button>
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('dossiers.drive_new_folder_submit') }}</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </template>
                </div>
            @endif
        @endcan

        {{-- Sous le Drive, deux lentilles qui ne sont pas des contenus :
             l'editorial (Series & gestion des Articles) et les acces
             (Membres). Fermees par defaut — la page, c'est le Drive. --}}
        <div x-data="{ panneau: null }" class="mt-8">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="panneau = panneau === 'series' ? null : 'series'" x-bind:aria-expanded="panneau === 'series'"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-xl border px-3.5 text-sm font-semibold transition"
                        :class="panneau === 'series' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-300' : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:text-gray-200'">
                    {{ __('dossiers.series_tab') }}
                    <svg class="h-3.5 w-3.5 transition-transform" :class="panneau === 'series' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                </button>
                <button type="button" @click="panneau = panneau === 'membres' ? null : 'membres'" x-bind:aria-expanded="panneau === 'membres'"
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-xl border px-3.5 text-sm font-semibold transition"
                        :class="panneau === 'membres' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-300' : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:text-gray-200'">
                    {{ __('dossiers.members_tab') }}
                    <svg class="h-3.5 w-3.5 transition-transform" :class="panneau === 'membres' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                </button>
            </div>

            {{-- Tab: Series --}}            {{-- Tab: Series --}}
            <div x-show="panneau === 'series'" x-cloak class="mt-6">
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
                                 'clearSearchToReorder' => __('dossiers.clear_search_to_reorder'),
                             ],
                         ]))">
                    <div class="flex flex-col gap-4">
                        {{-- Header --}}
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.contents_tab') }}</h2>
                            <template x-if="canManageArticles">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    {{-- Creer une serie n'etait accessible que par le menu
                                         a trois points d'un article : personne ne l'y
                                         trouvait. --}}
                                    <button x-show="!hasSeries" @click="createSeries()" type="button" :disabled="saving"
                                            class="w-full whitespace-nowrap rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950/40 sm:w-auto">{{ __('dossiers.series_create') }}</button>
                                    <button @click="openAddArticleModal()" type="button" class="w-full whitespace-nowrap rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:w-auto">{{ __('dossiers.add_article') }}</button>
                                </div>
                            </template>
                        </div>
                        {{-- Search row --}}
                        <input x-model="searchQuery" type="text" placeholder="{{ __('dossiers.article_search_placeholder') }}" class="w-full rounded-lg border-gray-300 text-sm shadow-sm sm:max-w-md dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <template x-if="isSearchActive">
                            <p class="text-xs text-amber-600 dark:text-amber-400" x-text="i18n.clearSearchToReorder"></p>
                        </template>

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
                                                    <button @click="showSeriesMenu = false; openDeleteSeriesModal()" type="button" class="w-full text-left px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" x-text="i18n.deleteSeries"></button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                {{-- Root article --}}
                                <template x-if="seriesRoot">
                                    <div class="border-t border-indigo-200 dark:border-indigo-900/60">
                                        {{-- Zone de depot : glisser un article ici le promeut
                                             racine. Voir onDropOnRoot(). --}}
                                        <div class="px-4 py-3" x-ref="seriesRootContainer">
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
                                                         <span x-show="!isSearchActive" class="drag-handle mt-0.5 cursor-grab shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" :title="i18n.dragHandle">
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
                                                            <button @click="moveAnnex(index, -1)" :disabled="index === 0 || isSearchActive" :title="i18n.moveUp" type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700">
                                                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd"/></svg>
                                                            </button>
                                                            <button @click="moveAnnex(index, 1)" :disabled="index === filteredAnnexItems.length - 1 || isSearchActive" :title="i18n.moveDown" type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700">
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
                                                                {{-- Promouvoir cette annexe en racine. C'est ici que l'on
                                                                     cherche l'action, article par article — l'entree
                                                                     « Changer de racine » du menu de la serie ne faisait rien. --}}
                                                                <button @click="promoteToRoot(item)" type="button" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.setRoot"></button>
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
                                                <span x-show="!isSearchActive" class="drag-handle mt-0.5 cursor-grab shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" :title="i18n.dragHandle">
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
                                                    <button @click="moveUngrouped(index, -1)" :disabled="index === 0 || isSearchActive" :title="i18n.moveUp" type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700">
                                                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd"/></svg>
                                                    </button>
                                                    <button @click="moveUngrouped(index, 1)" :disabled="index === filteredUngrouped.length - 1 || isSearchActive" :title="i18n.moveDown" type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700">
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
                                                        {{-- Avec ou sans serie, designer la racine part
                                                             du meme menu : sans serie on la cree, avec
                                                             serie on remplace sa racine. --}}
                                                        <template x-if="!hasSeries && canManageArticles">
                                                            <button @click="setAsRoot(entry)" type="button" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.setRoot"></button>
                                                        </template>
                                                        <template x-if="hasSeries && canManageArticles">
                                                            <button @click="promoteToRoot(entry)" type="button" class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.setRoot"></button>
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

                    {{-- Choix de la racine : le Dossier contient deja plusieurs
                         articles, la question n'a pas de reponse evidente. --}}
                    <template x-if="showChooseRootModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                             @click.self="showChooseRootModal = false"
                             role="dialog" aria-modal="true" aria-labelledby="choose-root-title">
                            <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl dark:bg-gray-800">
                                <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                                    <h3 id="choose-root-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.series_choose_root_title') }}</h3>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.series_choose_root_help') }}</p>
                                </div>
                                <div class="max-h-80 space-y-1 overflow-y-auto px-3 py-3">
                                    <template x-for="entry in ungrouped" :key="entry.id">
                                        <button type="button" @click="chooseRoot(entry)" :disabled="saving"
                                                class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm text-gray-800 transition hover:bg-indigo-50 disabled:opacity-50 dark:text-gray-100 dark:hover:bg-indigo-950/40">
                                            <span class="min-w-0 flex-1 truncate" x-text="entry.blog_post?.title"></span>
                                        </button>
                                    </template>
                                </div>
                                <div class="flex justify-end border-t border-gray-200 px-5 py-3 dark:border-gray-700">
                                    <button type="button" @click="showChooseRootModal = false"
                                            class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">{{ __('dossiers.cancel') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Add Article Modal --}}
                    <template x-if="showAddModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeAddModal()" role="dialog" aria-modal="true" aria-labelledby="add-article-title">
                            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <div class="flex items-center justify-between">
                                    <h3 id="add-article-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.add_article_title') }}</h3>
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
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showDeleteSeriesModal = false" role="dialog" aria-modal="true" aria-labelledby="delete-series-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="delete-series-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.seriesDeleteModalTitle"></h3>
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
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showDetachModal = false; detachEntry = null" role="dialog" aria-modal="true" aria-labelledby="detach-article-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="detach-article-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.detachModalTitle"></h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300" x-text="i18n.detachModalBody"></p>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button @click="showDetachModal = false; detachEntry = null" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.cancel"></button>
                                    <button @click="detachArticle()" :disabled="detaching" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50" x-text="i18n.removeFromFolder"></button>
                                </div>
                            </div>
                        </div>
                    </template>
                </section>

                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                    <header class="mb-5 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.series_tab') }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ trans_choice('dossiers.series_count_items', $seriesList->count(), ['count' => $seriesList->count()]) }}
                        </p>
                    </header>

                    @if ($seriesList->isEmpty())
                        <div class="rounded-2xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-gray-600">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.series_tab_empty_title') }}</h3>
                            <p class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.series_tab_empty_body') }}</p>
                        </div>
                    @else
                        {{-- Une Serie par bloc, chacune avec sa propre zone de
                             classement. Une seule zone commune aurait laisse
                             glisser un contenu d'une Serie a l'autre, ce que le
                             serveur refuse : un contenu n'appartient qu'a une
                             seule Serie. --}}
                        <div class="space-y-5">
                            @foreach ($seriesList as $uneSerie)
                                <x-dossiers.series-list
                                    :series="$uneSerie"
                                    :can-manage="$canManageArticles"
                                    :organization-param="$organizationRouteParam"
                                    :dossier-id="$dossier->id"
                                />
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            {{-- Tab: Members --}}
            <div x-show="panneau === 'membres'" x-cloak class="mt-6">
                @if($dossier->isLoopDossier())
                    {{-- Dossier racine : les accès sont ceux de la Boucle, en
                         lecture seule. Aucune gestion parallèle ici — la Boucle
                         est la source de vérité, et c'est chez elle qu'on gère. --}}
                    @php
                        $registreRoles = app(\App\Support\Loops\LoopRoleRegistry::class);
                        $ordreRoles = [\App\Support\Loops\LoopRoleRegistry::OWNER => 0, \App\Support\Loops\LoopRoleRegistry::FACILITATOR => 1];
                        $accesBoucle = ($dossier->loop?->activeMembers ?? collect())
                            ->sortBy(fn ($m) => [$ordreRoles[$registreRoles->canonical($m->role)] ?? 2, $m->joined_at]);
                    @endphp
                    <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.members_access_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.members_managed_by_loop') }}</p>

                        <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($accesBoucle as $membreBoucle)
                                <li class="flex items-center justify-between gap-3 py-2.5">
                                    <span class="min-w-0 truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                        {{ $membreBoucle->user?->isDisplayableIn(currentOrganization()) ? $membreBoucle->user->publicDisplayName() : __('profile.deactivated_user') }}
                                    </span>
                                    <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                        {{ __('dossiers.role_loop_'.$registreRoles->canonical($membreBoucle->role)) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        @if($dossier->loop)
                            <a href="{{ route('organization.loops.show', ['organization' => $orgParam, 'loop' => $dossier->loop]) }}"
                               class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                {{ __('dossiers.members_open_loop') }} →
                            </a>
                        @endif
                    </section>
                @else
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6"
                         x-data="dossierMembersCard(@js([
                             'csrfToken' => csrf_token(),
                             'dossierId' => $dossier->getKey(),
                             'orgParam' => $orgParam,
                             'ownerId' => $dossier->owner_id,
                              'ownerName' => $dossier->owner?->publicDisplayName() ?? __('profile.deactivated_user'),
                              'ownerInitial' => $ownerDisplayable ? strtoupper(substr($dossier->owner->first_name ?? $dossier->owner->name ?? '?', 0, 1)) : '?',
                             'currentUserId' => auth()->id(),
                             'canManage' => $canManageMembers,
                              'i18n' => array_merge([
                                  'confirmRemove' => __('dossiers.confirm_remove_member'),
                                  'memberAdded' => __('dossiers.member_added'),
                                  'memberRoleUpdated' => __('dossiers.member_role_updated'),
                                  'memberRemoved' => __('dossiers.member_removed'),
                                  'memberAlready' => __('dossiers.member_already'),
                                  'roleReader' => __('dossiers.role_reader'),
                                  'roleEditor' => __('dossiers.role_editor'),
                                  'ownerBadge' => __('dossiers.owner_badge'),
                                  'yourRole' => __('dossiers.your_role_label'),
                                  'personSingular' => __('dossiers.person_singular'),
                                  'personPlural' => __('dossiers.person_plural'),
                              ], $canManageMembers ? [
                                 'manageMembers' => __('dossiers.manage_members'),
                                 'removeMemberTitle' => __('dossiers.remove_member_title'),
                                 'removeMemberBody' => __('dossiers.remove_member_body'),
                                 'removeMemberConfirm' => __('dossiers.remove_member_confirm'),
                                 'cancel' => __('dossiers.cancel'),
                                 'addMember' => __('dossiers.add_member'),
                                 'addMemberHelp' => __('dossiers.add_member_help'),
                                 'searchPlaceholder' => __('dossiers.member_search_placeholder'),
                                 'noMembers' => __('dossiers.no_members'),
                             ] : []),
                         ]))">
                    <div x-show="message" x-transition
                         :class="messageType === 'error' ? 'bg-red-50 border-red-200 text-red-800 dark:bg-red-950/40 dark:border-red-900/60 dark:text-red-200' : 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-900/60 dark:text-emerald-200'"
                         class="mb-4 rounded-xl border px-4 py-3 text-sm font-medium">
                        <span x-text="message"></span>
                    </div>

                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-4">
                            <div class="flex -space-x-2">
                                <div class="relative flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 text-sm font-bold text-amber-700 ring-2 ring-white dark:bg-amber-950/60 dark:text-amber-300 dark:ring-gray-800" title="{{ __('dossiers.owner_badge') }}">
                                    <span x-text="ownerInitial"></span>
                                    <span class="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-amber-500 text-[8px] text-white ring-2 ring-white dark:ring-gray-800">
                                        <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a5 5 0 00-5 5v2a2 2 0 00-2 2v5h14v-5a2 2 0 00-2-2V7a5 5 0 00-5-5z"/></svg>
                                    </span>
                                </div>
                                <template x-for="(m, idx) in displayMembers" :key="m.id">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-700 ring-2 ring-white dark:bg-indigo-950/60 dark:text-indigo-300 dark:ring-gray-800" :title="m.displayName" x-text="m.initial"></div>
                                </template>
                                <template x-if="overflowCount > 0">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-200 text-sm font-semibold text-gray-600 ring-2 ring-white dark:bg-gray-700 dark:text-gray-300 dark:ring-gray-800">
                                        <span x-text="'+' + overflowCount"></span>
                                    </div>
                                </template>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="ownerName"></span>
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/60 dark:text-amber-300" x-text="i18n.ownerBadge"></span>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="(members.length + 1) + ' ' + ((members.length + 1) === 1 ? i18n.personSingular : i18n.personPlural)"></span>
                                    <span class="mx-1">·</span>
                                    <span x-text="i18n.yourRole"></span>
                                    <span class="font-medium" x-text="currentRoleLabel"></span>
                                </p>
                            </div>
                        </div>
                        @if($canManageMembers)
                            <button @click="showManageModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <span x-text="i18n.manageMembers"></span>
                            </button>
                        @endif
                    </div>

                    {{-- Management Modal (owner only) --}}
                    @if($canManageMembers)
                    <template x-if="showManageModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-4" @click.self="showManageModal = false" role="dialog" aria-modal="true" aria-labelledby="manage-members-title">
                            <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800 max-h-[90vh] overflow-y-auto">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 id="manage-members-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.manageMembers"></h3>
                                    <button @click="showManageModal = false" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="{{ __('dossiers.cancel') }}">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>

                                <div class="mb-4">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2" x-text="i18n.addMemberHelp"></p>
                                    <input type="text" x-model="searchQuery" @input.debounce.300ms="searchUsers()" :placeholder="i18n.searchPlaceholder"
                                           class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                                </div>

                                <template x-if="searchLoading">
                                    <div class="text-xs text-gray-400 mb-3">...</div>
                                </template>

                                <template x-if="searchResults.length > 0">
                                    <div class="mb-4 space-y-2">
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
                                                        <option value="reader" x-text="i18n.roleReader"></option>
                                                        <option value="editor" x-text="i18n.roleEditor"></option>
                                                    </select>
                                                    <button @click="addMember(u)" class="inline-flex w-full items-center justify-center gap-1 rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 sm:w-auto">
                                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                                        <span x-text="i18n.addMember"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <div class="space-y-2">
                                    <div class="flex items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 dark:bg-amber-950/20">
                                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 dark:bg-amber-950/60 dark:text-amber-300" x-text="ownerInitial"></div>
                                        <div class="flex-1">
                                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="ownerName"></div>
                                            <span class="inline-block mt-0.5 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/60 dark:text-amber-300" x-text="i18n.ownerBadge"></span>
                                        </div>
                                    </div>

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
                                                    <option value="reader" x-text="i18n.roleReader"></option>
                                                    <option value="editor" x-text="i18n.roleEditor"></option>
                                                </select>
                                                <button @click="openRemoveModal(m)"
                                                        class="inline-flex h-8 w-full items-center justify-center gap-1 rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/30 dark:hover:text-red-400 sm:w-8"
                                                        :title="i18n.removeMemberConfirm">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="members.length === 0">
                                        <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300" x-text="i18n.noMembers"></p>
                                    </template>
                                </div>

                                <div class="mt-4 flex justify-end">
                                    <button @click="showManageModal = false" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.cancel"></button>
                                </div>
                            </div>
                        </div>
                    </template>
                    @endif

                    {{-- Remove Confirmation Modal (owner only) --}}
                    @if($canManageMembers)
                    <template x-if="showRemoveModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showRemoveModal = false" role="dialog" aria-modal="true" aria-labelledby="remove-member-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800">
                                <h3 id="remove-member-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.removeMemberTitle"></h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                    <span x-text="removeTarget?.displayName"></span> — <span x-text="i18n.removeMemberBody"></span>
                                </p>
                                <div class="mt-4 flex justify-end gap-2">
                                    <button @click="showRemoveModal = false; removeTarget = null" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.cancel"></button>
                                    <button @click="confirmRemove()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-600" x-text="i18n.removeMemberConfirm"></button>
                                </div>
                            </div>
                        </div>
                    </template>
                    @endif
                </section>
                @endif
            </div>
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
