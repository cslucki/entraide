<x-app-layout>
    @php
        $orgParam = request()->route('organization');
        $entries = $dossier->dossierBlogPosts->filter(fn ($entry) => $entry->blogPost !== null)->values();

        $canView = fn ($bp) => $canManageArticles || ($bp->status === 'published');
        $blogShowRoute = fn ($bp) => $bp && $canView($bp)
            ? route('organization.blog.show', ['organization' => $orgParam, 'post' => $bp->slug])
            : null;
        $blogEditRoute = fn ($bp) => $bp && $canManageArticles
            ? route('organization.blog.edit', ['organization' => $orgParam, 'post' => $bp->slug])
            : null;
        // Le proprietaire affiche est toujours celui de la racine gouvernante :
        // un enfant n'a pas d'owner_id a lui (TASK-1130 passe 4).
        $ownerDisplayable = $governingDossier->owner?->isDisplayableIn(currentOrganization()) ?? false;

        // Colonnes du Drive (TASK-1130, doctrine Cyril) : memes colonnes et
        // meme gabarit qu'a l'index — revelees progressivement, les cellules
        // masquees sortant du flux grid. Sous `lg`, la ligne redevient un
        // resume compact ; le nom reste la donnee principale.
        $grilleDrive = 'grid grid-cols-[minmax(0,1fr)_2.75rem] items-center gap-x-3'
            .' lg:grid-cols-[minmax(0,3fr)_9rem_6.5rem_6.5rem_6.5rem_2.75rem]';
        $celluleLgDrive = 'hidden lg:block min-w-0 truncate text-xs text-gray-500 dark:text-gray-400';
        // « Prive » est le cas dominant : explicite, mais rendu le plus discret
        // des trois etats (reference canonique drive-v2).
        // Dans un Drive de Boucle, « Partage » repete la meme valeur sur chaque
        // ligne — et le fil d'Ariane la dit deja. La colonne devient « Type »,
        // qui, la, distingue reellement les lignes.
        $colonneEstType = $governingDossier->isLoopDossier();
        $classePartage = fn (string $etat) => $etat === __('dossiers.share_private')
            ? 'hidden lg:block min-w-0 truncate text-xs text-gray-400 dark:text-gray-500'
            : 'hidden lg:block min-w-0 truncate text-xs text-gray-500 dark:text-gray-400';

        // La gouvernance HERITEE de ce Dossier : ce que porte tout contenu qui
        // y vit, sauf un CAS B qui dit la sienne.
        $partageHerite = $governingDossier->isLoopDossier()
            ? __('dossiers.share_loop')
            : ($governingDossier->dossierMembers->isNotEmpty() ? __('dossiers.share_shared') : __('dossiers.share_private'));
        $proprietaireHerite = $governingDossier->isLoopDossier()
            ? ($governingDossier->loop?->name ?? '—')
            : ($governingDossier->owner_id === auth()->id()
                ? __('dossiers.owner_me')
                : ($governingDossier->owner?->isDisplayableIn(currentOrganization()) ? $governingDossier->owner->publicDisplayName() : __('profile.deactivated_user')));

        // Mode Serie (TASK-1130, doctrine finale) : la projection SEQUENTIELLE de
        // chaque Serie — les contenus reels du Dossier avec un rang, jamais
        // dupliques, jamais renommes. `numberedContents()` est la meme
        // numerotation calculee que partout ailleurs.
        $seriesModeData = $seriesList->map(fn ($s) => [
            'id' => $s->getKey(),
            'name' => $s->displayName() ?: __('dossiers.series_untitled'),
            'items' => $s->numberedContents()->map(fn ($e) => [
                'itemId' => $e['item']?->getKey(),
                'type' => $e['type'],
                'name' => $e['name'],
                'key' => $e['content'] instanceof \App\Models\BlogPost
                    ? 'blog:'.$e['content']->getKey()
                    : ($e['content'] instanceof \App\Models\DossierFile ? 'file:'.$e['content']->getKey() : null),
            ])->values(),
        ])->values();

        // Candidats Articles pour « Ajouter a la serie » : ceux deja attaches
        // au Dossier (les fichiers candidats viennent de l'etat JS `files`).
        $serieArticlesData = $entries->map(fn ($entry) => [
            'id' => $entry->blog_post_id,
            'title' => $entry->blogPost->title,
        ])->values();
    @endphp

    <x-slot name="title">{{ $dossier->displayName() }} — {{ __('navigation.my_dossiers') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        {{-- TASK-1130 (UX finale) : une seule identite. Plus de « Retour aux
             dossiers » ni de H1 repetant le breadcrumb — le fil d'Ariane de
             la barre du Drive EST l'identite de la page, « Documents » (l'index)
             en premiere miette. Le badge de contexte et le crayon de renommage
             vivent a cote de la miette courante. --}}

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
                {{-- La portee Alpine englobe la sidebar du module : « + Nouveau »
                     y vit au bureau (reference canonique drive-v2) et pilote les
                     memes actions que sur mobile. --}}
                <div x-data="dossierFilesCard(@js([
                             'csrfToken' => csrf_token(),
                             'dossierId' => $dossier->getKey(),
                             'orgParam' => $orgParam,
                             'canManageFiles' => $canManageFiles,
                             'canDeleteFiles' => $canDeleteFiles,
                             'moveTargets' => $moveTargets,
                             'seriesMode' => $seriesModeData,
                             'serieArticles' => $serieArticlesData,
                             'canManageSeries' => $canManageSeries,
                             'dossierName' => $dossier->displayName(),
                             'activeTab' => 'fichiers',
                             'nomsSpatiaux' => $driveFolders->pluck('name')
                                 ->merge($dossier->dossierBlogPosts->map(fn ($e) => $e->blogPost?->title))
                                 ->filter()->map(fn ($n) => mb_strtolower($n))->values(),
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
                                  // La portee est dite par le champ lui-meme :
                                  // « ce dossier » ne nomme rien quand on est
                                  // dans l'espace personnel, qui a un nom.
                                  'searchPlaceholder' => $dossier->isPersonalDocumentsRoot()
                                      ? __('dossiers.search_in_my_documents')
                                      : __('dossiers.file_search_placeholder'),
                                  'move' => __('dossiers.file_move'),
                                  'moved' => __('dossiers.file_moved'),
                                  'movedTo' => __('dossiers.file_moved_to'),
                                  'folderItemsOne' => trans_choice('dossiers.drive_folder_items', 1, ['count' => 1]),
                                  'folderItemsMany' => trans_choice('dossiers.drive_folder_items', 2, ['count' => ':count']),
                                  'serieAdded' => __('dossiers.annex_added'),
                                  'serieRemoved' => __('dossiers.annex_removed'),
                                  'serieCreated' => __('dossiers.series_created'),
                                  'serieDeleted' => __('dossiers.series_deleted'),
                                  'serieReorderFailed' => __('dossiers.series_reorder_failed'),
                                  'dragHandle' => __('dossiers.content_drag_handle'),
                                  'moveFailed' => __('dossiers.file_move_failed'),
                                  'moveModalTitle' => __('dossiers.drive_move_modal_title'),
                                  'moveToParent' => __('dossiers.drive_move_to_parent'),
                                  'moveNoTargets' => __('dossiers.drive_move_no_targets'),
                                  'moveConfirm' => __('dossiers.drive_move_confirm'),
                                  'moveCancel' => __('dossiers.drive_move_cancel'),
                                  'folderDeleteTitle' => __('dossiers.drive_folder_confirm_delete_title'),
                                  'folderDeleteBody' => __('dossiers.drive_folder_confirm_delete_body'),
                                  'folderDeleteCancel' => __('dossiers.dossier_confirm_delete_cancel'),
                                  'folderDeleteConfirm' => __('dossiers.dossier_confirm_delete_confirm'),
                                  'viewList' => __('dossiers.file_view_list'),
                                  'viewGrid' => __('dossiers.file_view_grid'),
                                  'ownerMe' => __('dossiers.owner_me'),
                                  'renameTitle' => __('dossiers.file_rename_title'),
                                  'renameLabel' => __('dossiers.file_rename_label'),
                                  'searchNoResults' => __('dossiers.search_no_results'),
                              ],
                         ]))">
                <x-dossiers.module :espace="$espace">
                    @if($canManageFiles)
                        <x-slot name="nouveau">
                            @include('dossiers.partials.nouveau', ['avecRef' => true, 'classesBouton' => 'w-full', 'ancrageMenu' => 'left-0'])
                        </x-slot>
                    @endif

                {{-- La surface est nue : les lignes se posent sur le fond, sans
                     grande carte blanche englobante (reference canonique). --}}
                <section class="pt-4">
                    {{-- Depot par glisser : la surcouche s'allume au survol d'un
                         vrai fichier, et le depot part dans l'upload existant —
                         le meme que les entrees du menu, aucun second moteur.
                         Le wrapper est rendu pour TOUS les roles (TASK-1130 UX
                         finale) : seul son comportement est conditionnel. Un
                         wrapper ouvert pour certains roles et ferme pour
                         d'autres etait la moitie du desequilibre HTML qui
                         faisait vivre toute la page DANS la barre d'outils. --}}
                    <div class="relative"
                         @if($canManageFiles)
                         @dragenter.prevent="if (($event.dataTransfer?.types || []).includes('Files')) survol++"
                         @dragover.prevent
                         @dragleave.prevent="survol = Math.max(0, survol - 1)"
                         @drop.prevent="survol = 0; if ($event.dataTransfer?.files?.length) handleMediaFiles({ target: { files: $event.dataTransfer.files, value: '' } }, 'drop')"
                         @endif
                         >
                        @if($canManageFiles)
                        <div x-show="survol > 0" x-cloak
                             class="pointer-events-none absolute inset-0 z-40 flex items-center justify-center rounded-2xl border-2 border-dashed border-indigo-400 bg-indigo-50/90 dark:border-indigo-500 dark:bg-indigo-950/80">
                            <p class="text-base font-semibold text-indigo-700 dark:text-indigo-200">{{ __('dossiers.drive_drop_here') }}</p>
                        </div>
                        @endif

                    {{-- La barre du Drive : ou l'on est, chercher, creer. --}}
                    <div class="flex flex-wrap items-center gap-3">
                        {{-- Breadcrumb recursif (TASK-1130 passe 4) : la chaine
                             reelle de `parent_id`, jamais un chemin recompose a
                             partir d'informations de presentation. Chaque
                             niveau est un lien, sauf le dernier qui est la
                             position. --}}
                        @php
                            $cibleParent = $moveTargets->firstWhere('isParent', true);
                        @endphp
                        {{-- Le fil d'Ariane est l'identite de la page (TASK-1130
                             UX finale) : « Documents » (l'index) en premiere
                             miette, puis la chaine reelle de parent_id, puis la
                             position courante — plus aucun H1 ne la repete.
                             Chaque maillon cliquable est une cible d'au moins
                             44px de haut. --}}
                        <nav class="flex w-full min-w-0 flex-wrap items-center gap-x-1.5 text-sm sm:w-auto sm:flex-1" aria-label="Breadcrumb">
                            {{-- La premiere miette est l'espace d'ou l'on vient :
                                 « Mes documents » pour un Dossier personnel,
                                 « Boucles » pour le Drive d'une Boucle. Sur la
                                 racine personnelle elle-meme, elle est omise :
                                 la position courante EST « Mes documents », et
                                 une miette qui pointe sur la page affichee
                                 n'apprend rien. --}}
                            @unless($dossier->isPersonalDocumentsRoot())
                                <a href="{{ $espace === 'boucles'
                                        ? route('organization.dossiers.index', ['organization' => $orgParam, 'espace' => 'boucles'])
                                        : route('organization.dossiers.index', ['organization' => $orgParam]) }}"
                                   class="inline-flex min-h-11 shrink-0 items-center rounded font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $espace === 'boucles' ? __('dossiers.space_loops') : __('dossiers.space_my_documents') }}</a>
                                <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                            @endunless
                            @foreach($breadcrumbAncestors as $ancetre)
                                {{-- La racine personnelle est deja la premiere
                                     miette : la repeter donnait « Mes documents ›
                                     Mes documents › ... ». --}}
                                @continue($ancetre->isPersonalDocumentsRoot())
                                {{-- Le dernier maillon est le parent reel : glisser
                                     un fichier ici le remonte d'un niveau, meme
                                     action que "Deplacer vers... > Dossier parent". --}}
                                @php
                                    $estParentReel = $cibleParent && $ancetre->getKey() === $cibleParent['id'];
                                @endphp
                                <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $ancetre->getKey()]) }}"
                                   class="inline-flex min-h-11 max-w-[10rem] items-center rounded font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                   @if($estParentReel)
                                   @dragover.prevent="onFolderDragOver('{{ $ancetre->getKey() }}')"
                                   @dragleave="onFolderDragLeave('{{ $ancetre->getKey() }}')"
                                   @drop.prevent="onFolderDrop('{{ $ancetre->getKey() }}')"
                                   :class="dragOverFolderId === '{{ $ancetre->getKey() }}' ? 'bg-indigo-50 ring-2 ring-indigo-400 dark:bg-indigo-950/30' : ''"
                                   @endif
                                   ><span class="truncate">{{ $ancetre->isLoopDossier() ? ($ancetre->loop?->name ?? __('dossiers.drive_breadcrumb_root')) : $ancetre->name }}</span></a>
                                <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                            @endforeach
                            <span class="inline-flex min-h-11 max-w-[16rem] items-center gap-2" aria-current="page">
                                <span class="truncate text-base font-semibold text-gray-900 dark:text-gray-100">{{ $dossier->isLoopDossier() ? ($dossier->loop?->name ?? __('dossiers.drive_breadcrumb_root')) : $dossier->displayName() }}</span>
                            </span>
                            {{-- Contexte et gestes d'identite, a cote de la
                                 position courante. Le role n'apparait que s'il
                                 explique une limitation reelle (lecture seule) —
                                 jamais en chip generique. --}}
                            @if($dossier->isPersonalDocumentsRoot())
                                {{-- « Mes documents » ne se renomme pas : le
                                     crayon n'est pas cache par prudence, il
                                     n'existe pas — la policy `rename` refuserait
                                     de toute facon la requete. --}}
                            @elseif($governingDossier->isLoopDossier())
                                <span class="shrink-0 rounded-full bg-indigo-50 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-200">{{ __('dossiers.loop_dossier_badge') }}</span>
                            @elseif($userRole === 'owner')
                                <a href="{{ route('organization.dossiers.edit', ['organization' => $orgParam, 'dossier' => $dossier->getKey()]) }}" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200" title="{{ __('dossiers.rename') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </a>
                                {{-- Le partage est un geste d'IDENTITE, comme le
                                     renommage : il vit a cote de lui, pas a
                                     l'autre bout de la barre parmi les outils de
                                     la surface. L'icone dit aussi l'etat — teintee
                                     des que le dossier est partage. --}}
                                @php
                                    $dejaPartage = $dossier->shared_with_loop_id !== null || $dossier->dossierMembers->isNotEmpty();
                                @endphp
                                <button type="button" @click="window.dispatchEvent(new CustomEvent('open-share-panel'))"
                                        class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg transition {{ $dejaPartage
                                            ? 'text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-indigo-950/40'
                                            : 'text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200' }}"
                                        title="{{ $dejaPartage ? __('dossiers.share_manage') : __('dossiers.share_tab') }}"
                                        aria-label="{{ $dejaPartage ? __('dossiers.share_manage') : __('dossiers.share_tab') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
                                </button>
                            @else
                                <span class="shrink-0 rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-950/60 dark:text-amber-200">{{ __('dossiers.shared_badge') }}</span>
                                {{-- Lecture seule pour un membre lecteur COMME
                                     pour un visiteur par visibilite Organization
                                     (role 'none') : les deux limitations sont
                                     reelles, seul un role sans limite se tait. --}}
                                @if(in_array($userRole, ['reader', 'none'], true))
                                    <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('dossiers.read_only_badge') }}</span>
                                @endif
                            @endif
                        </nav>

                        {{-- La recherche filtre la vue spatiale ; en mode Serie
                             l'ordre est la question, pas le filtre — un
                             reordonnancement sur une projection filtree a deja
                             produit un bug ici, on ne le reinvite pas. --}}
                        <div class="relative w-full min-w-0 flex-1 sm:w-72 sm:flex-none" x-show="vue !== 'serie'">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <svg class="h-4 w-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                            </div>
                            <input x-model="searchQuery" @input.debounce.300ms="onSearchInput()" type="search"
                                   class="block w-full rounded-xl border border-gray-300 bg-white py-2 pl-10 pr-3 text-sm text-gray-900 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-400"
                                   :placeholder="i18n.searchPlaceholder || 'Search files…'">
                        </div>
                        <div class="flex shrink-0 gap-0.5 rounded-lg border border-gray-200 p-0.5 dark:border-gray-700 max-sm:order-3 max-sm:basis-full">
                            <button type="button" @click="setViewMode('list')" :aria-pressed="vue === 'documents' && viewMode === 'list'"
                                    class="flex h-11 w-11 items-center justify-center rounded-md transition sm:h-8 sm:w-8"
                                    :class="vue === 'documents' && viewMode === 'list' ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-200'"
                                    :aria-label="i18n.viewList" :title="i18n.viewList">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
                            </button>
                            <button type="button" @click="setViewMode('grid')" :aria-pressed="vue === 'documents' && viewMode === 'grid'"
                                    class="flex h-11 w-11 items-center justify-center rounded-md transition sm:h-8 sm:w-8"
                                    :class="vue === 'documents' && viewMode === 'grid' ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-200'"
                                    :aria-label="i18n.viewGrid" :title="i18n.viewGrid">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6a2.25 2.25 0 0 1 2.25-2.25h.75A2.25 2.25 0 0 1 9 6v.75a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 6.75V6Zm0 11.25A2.25 2.25 0 0 1 6 15h.75a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-.75ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25h.75A2.25 2.25 0 0 1 18.75 6v.75a2.25 2.25 0 0 1-2.25 2.25h-.75a2.25 2.25 0 0 1-2.25-2.25V6Zm0 11.25A2.25 2.25 0 0 1 15.75 15h.75a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25h-.75A2.25 2.25 0 0 1 13.5 18v-.75Z"/></svg>
                            </button>
                            <button type="button" @click="enterSerieToggle()" :aria-pressed="vue === 'serie'"
                                    class="flex h-11 w-11 items-center justify-center rounded-md transition sm:h-8 sm:w-8"
                                    :class="vue === 'serie' ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-200'"
                                    aria-label="{{ __('dossiers.series_mode_label') }}" title="{{ __('dossiers.series_mode_label') }}">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.362 1.093a.75.75 0 00-.724 0L2.523 5.018 10 9.143l7.477-4.125-7.115-3.925zM18 6.443l-7.25 4v8.25l6.862-3.786A.75.75 0 0018 14.25V6.443zm-8.75 12.25v-8.25l-7.25-4v7.807a.75.75 0 00.388.657l6.862 3.786z"/></svg>
                            </button>
                        </div>

                        {{-- Mobile : « + Nouveau » vit dans la ligne d'outils,
                             a cote de la recherche — la sidebar qui le porte au
                             bureau n'existe pas ici. Meme partial, meme etat,
                             aucun second moteur. --}}
                        @if($canManageFiles)
                            <div class="shrink-0 md:hidden max-sm:order-2">
                                @include('dossiers.partials.nouveau', ['avecRef' => false, 'classesBouton' => '', 'ancrageMenu' => 'left-0 sm:right-0 sm:left-auto'])
                            </div>
                        @endif


                    </div>

                    {{-- Sous-barre : trois modes d'une MEME surface —
                         Liste | Grille | Serie (TASK-1130, doctrine finale).
                         En mode Serie, la gauche porte le contexte de la
                         sequence : « Serie : X ▾ », le compte, l'ajout, et le
                         menu « ... » — jamais un panneau de gestion. --}}
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2" x-show="vue === 'serie'" x-cloak>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('dossiers.series_mode_label') }} :</span>
                            <div class="relative" x-data="{ ouvert: false }" x-on:keydown.escape.window="ouvert = false">
                                <button type="button" @click="ouvert = !ouvert" x-bind:aria-expanded="ouvert"
                                        class="inline-flex min-h-11 max-w-[16rem] items-center gap-1.5 rounded-lg px-2 text-sm font-semibold text-gray-900 transition hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-700">
                                    <span class="truncate" x-text="serieActive ? serieActive.name : '{{ __('dossiers.series_mode_pick') }}'"></span>
                                    <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                                </button>
                                <div x-show="ouvert" x-cloak @click.outside="ouvert = false"
                                     class="absolute left-0 z-30 mt-1 w-72 rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                    <template x-for="serie in seriesMode" :key="serie.id">
                                        <button type="button" @click="ouvert = false; enterSerieMode(serie.id)"
                                                class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700"
                                                :class="serieActive && serieActive.id === serie.id && 'font-semibold text-indigo-700 dark:text-indigo-300'">
                                            <span class="min-w-0 flex-1 truncate text-left" x-text="serie.name"></span>
                                            <span class="shrink-0 text-xs tabular-nums text-gray-400" x-text="serie.items.length"></span>
                                        </button>
                                    </template>
                                    @if($canManageSeries)
                                        <div class="my-1 border-t border-gray-100 dark:border-gray-700" x-show="seriesMode.length"></div>
                                        <button type="button" @click="ouvert = false; newSerieName = ''; showCreateSerieModal = true"
                                                class="flex w-full items-center gap-3 px-4 py-2.5 text-sm font-medium text-indigo-600 hover:bg-indigo-50/60 dark:text-indigo-300 dark:hover:bg-indigo-950/40">
                                            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                            {{ __('dossiers.series_mode_create') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <template x-if="serieActive">
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="serieActive.items.length === 1 ? i18n.folderItemsOne : i18n.folderItemsMany.replace(':count', serieActive.items.length)"></span>
                            </template>
                            @if($canManageSeries)
                                <template x-if="serieActive">
                                    <button type="button" @click="showSerieAddModal = true"
                                            class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                        {{ __('dossiers.series_mode_add') }}
                                    </button>
                                </template>
                                <template x-if="serieActive">
                                    <div class="relative shrink-0" x-data="{ ouvert: false }" x-on:keydown.escape.window="ouvert = false">
                                        <button type="button" @click="ouvert = !ouvert" x-bind:aria-expanded="ouvert"
                                                class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                :aria-label="serieActive.name">
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                        </button>
                                        <div x-show="ouvert" x-cloak @click.outside="ouvert = false"
                                             class="absolute left-0 top-full z-30 mt-1 w-52 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg sm:left-auto sm:right-0 dark:border-gray-700 dark:bg-gray-800">
                                            <button type="button" @click="ouvert = false; showSerieDeleteModal = true"
                                                    class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.series_mode_delete') }}</button>
                                        </div>
                                    </div>
                                </template>
                            @endif
                        </div>
                    </div>

                    {{-- Recherche semantique (pilote, gatee par
                         DossierSemanticSearchGate) : re-logee en bloc autonome
                         discret de la vue Documents — elle cherche des
                         passages dans les articles du dossier, ce n'est ni une
                         fonction Serie ni un panneau de gestion. Markup
                         d'origine inchange. --}}
                    <div x-show="vue === 'documents'">
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
                    </div>

                    {{-- Etats du mode Serie sans sequence ouverte : zero
                         Serie -> inviter a creer ; plusieurs -> demander
                         laquelle, jamais choisir a la place de la personne. --}}
                    <div x-show="vue === 'serie' && !serieActive && !seriesMode.length" x-cloak
                         class="mt-4 rounded-xl border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.series_tab_empty_title') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.series_tab_empty_body') }}</p>
                        @if($canManageSeries)
                            <button type="button" @click="newSerieName = ''; showCreateSerieModal = true"
                                    class="mt-4 inline-flex min-h-11 items-center gap-1.5 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                {{ __('dossiers.series_mode_create') }}
                            </button>
                        @endif
                    </div>
                    <div x-show="vue === 'serie' && !serieActive && seriesMode.length" x-cloak
                         class="mt-4 rounded-xl border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.series_mode_pick_help') }}</p>
                    </div>

                    {{-- La projection sequentielle : les contenus de la Serie,
                         dans son ordre, numerotes 01, 02… Le rang est calcule,
                         jamais recopie — rien n'est renomme, rien n'est
                         deplace. Seuls les membres de la Serie apparaissent et
                         portent numero et poignee ; le drag de la poignee ne
                         change QUE la position (le deplacement Drive vit en
                         mode normal, jamais ici). --}}
                    <template x-if="vue === 'serie' && serieActive">
                        <div class="mt-4 rounded-xl border border-gray-200 dark:border-gray-700">
                            <p class="sr-only" role="status" aria-live="polite" x-text="serieAnnouncement"></p>
                            <ul class="divide-y divide-gray-100 rounded-xl bg-white dark:divide-gray-700/60 dark:bg-gray-800">
                                <template x-for="(item, index) in serieActive.items" :key="item.itemId || item.key || index">
                                    <li class="flex items-center gap-2 px-4 py-3 transition first:rounded-t-xl last:rounded-b-xl sm:gap-3"
                                        :draggable="(canManageSeries && serieDragArmedId === item.itemId && !!item.itemId) ? 'true' : 'false'"
                                        @dragstart="if (item.itemId && serieDragArmedId === item.itemId) { serieDragItemId = item.itemId; } else { $event.preventDefault(); }"
                                        @dragend="serieDragArmedId = null; serieDragItemId = null; serieDragOverId = null"
                                        @dragover.prevent="if (serieDragItemId && item.itemId) serieDragOverId = item.itemId"
                                        @dragleave="if (serieDragOverId === item.itemId) serieDragOverId = null"
                                        @drop.prevent="serieDropOn(item.itemId)"
                                        :class="serieDragOverId === item.itemId && serieDragItemId !== item.itemId ? 'ring-2 ring-inset ring-indigo-400 bg-indigo-50/60 dark:bg-indigo-950/30' : (serieDragItemId === item.itemId ? 'opacity-40' : '')">
                                        <span class="w-7 shrink-0 text-center font-mono text-xs font-bold tabular-nums text-indigo-600 dark:text-indigo-300" x-text="String(index + 1).padStart(2, '0')"></span>
                                        @if($canManageSeries)
                                        <template x-if="item.itemId">
                                            <span class="hidden h-11 w-7 shrink-0 cursor-grab touch-none select-none items-center justify-center text-gray-300 hover:text-gray-500 active:cursor-grabbing sm:flex dark:text-gray-600 dark:hover:text-gray-400"
                                                  @mousedown="serieDragArmedId = item.itemId" @mouseup="serieDragArmedId = null"
                                                  :title="i18n.dragHandle" aria-hidden="true">
                                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                            </span>
                                        </template>
                                        <template x-if="!item.itemId">
                                            <span class="hidden w-7 shrink-0 sm:block" aria-hidden="true"></span>
                                        </template>
                                        @endif
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                              :class="item.type === 'file' ? 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400' : 'bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300'" aria-hidden="true">
                                            <svg x-show="item.type !== 'file'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                                            <svg x-show="item.type === 'file'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        </span>
                                        <span class="flex min-h-11 min-w-0 flex-1 flex-col justify-center">
                                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="item.name"></span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                                <span x-text="item.type === 'file' ? '{{ __('dossiers.drive_type_file') }}' : '{{ __('dossiers.drive_article_badge') }}'"></span><span x-show="item.type === 'root'"> · {{ __('dossiers.content_root_badge') }}</span>
                                            </span>
                                        </span>
                                        @if($canManageSeries)
                                        <template x-if="item.itemId">
                                            <span class="flex shrink-0 items-center">
                                                <button type="button" @click="serieMove(item.itemId, -1)" :disabled="serieSaving || !serieCanMoveUp(item.itemId)"
                                                        class="hidden h-11 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-30 sm:flex dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                        :aria-label="'{{ __('dossiers.move_up') }} — ' + item.name">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                                                </button>
                                                <button type="button" @click="serieMove(item.itemId, 1)" :disabled="serieSaving || !serieCanMoveDown(item.itemId)"
                                                        class="hidden h-11 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-30 sm:flex dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                        :aria-label="'{{ __('dossiers.move_down') }} — ' + item.name">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                                </button>
                                                <span class="relative" x-data="{ ouvert: false }" x-on:keydown.escape.window="ouvert = false">
                                                    <button type="button" @click="ouvert = !ouvert" x-bind:aria-expanded="ouvert"
                                                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-400 sm:w-9 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                            :aria-label="item.name">
                                                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                                    </button>
                                                    <span x-show="ouvert" x-cloak @click.outside="ouvert = false"
                                                          class="absolute right-0 top-full z-30 mt-1 block w-52 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                                        <button type="button" @click="ouvert = false; serieMove(item.itemId, -1)" :disabled="serieSaving || !serieCanMoveUp(item.itemId)"
                                                                class="flex min-h-11 w-full items-center rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40 sm:min-h-0 sm:hidden dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.move_up') }}</button>
                                                        <button type="button" @click="ouvert = false; serieMove(item.itemId, 1)" :disabled="serieSaving || !serieCanMoveDown(item.itemId)"
                                                                class="flex min-h-11 w-full items-center rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40 sm:min-h-0 sm:hidden dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.move_down') }}</button>
                                                        <template x-if="item.type === 'article'">
                                                            <button type="button" @click="ouvert = false; serieSetRoot(item)" :disabled="serieSaving"
                                                                    class="flex min-h-11 w-full items-center rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 sm:min-h-0 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.content_set_root') }}</button>
                                                        </template>
                                                        <button type="button" @click="ouvert = false; serieRemove(item)" :disabled="serieSaving"
                                                                class="flex min-h-11 w-full items-center rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 sm:min-h-0 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.content_remove_from_series') }}</button>
                                                    </span>
                                                </span>
                                            </span>
                                        </template>
                                        @endif
                                    </li>
                                </template>
                                <li x-show="!serieActive.items.length" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.series_mode_items_empty') }}</li>
                            </ul>
                        </div>
                    </template>

                    {{-- Toast ancre en bas de l'ecran (TASK-1130 UX finale) :
                         il vivait DANS la barre d'outils et deplacait le
                         layout a chaque message. bottom-24 sous sm : au-dessus
                         du FAB global et de la barre de navigation mobile. --}}
                    <div x-show="message" x-transition x-cloak role="status" aria-live="polite"
                         :class="messageType === 'error' ? 'bg-red-50 border-red-200 text-red-800 dark:bg-red-950/40 dark:border-red-900/60 dark:text-red-200' : 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-900/60 dark:text-emerald-200'"
                         class="fixed bottom-24 left-1/2 z-[70] w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 rounded-xl border px-4 py-3 text-sm font-medium shadow-lg sm:bottom-6">
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
                            <div class="flex min-h-11 min-w-0 flex-1 flex-col justify-center">
                            <div class="flex items-center justify-between gap-3">
                                <p class="truncate text-sm font-semibold text-indigo-950 dark:text-indigo-100">
                                    <span x-text="uploadFileName ? i18n.uploadingFile.replace(':name', uploadFileName) : i18n.uploading"></span>
                                    <span x-show="uploadTotal > 1" class="ml-1 font-normal opacity-80"
                                          x-text="'(' + (uploadFait + 1) + '/' + uploadTotal + ')'"></span>
                                </p>
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
                                    {{-- Le meme editeur que la description d'un
                                         service : on ecrit du texte, pas de la
                                         syntaxe. Il s'initialise a l'ouverture,
                                         la modale n'existant pas au chargement. --}}
                                    <div class="mt-1" x-init="$nextTick(() => document.dispatchEvent(new CustomEvent('bp:markdown-editor:init')))">
                                        <x-markdown-wysiwyg-editor name="dossier-md-content" :value="''" rows="8" :placeholder="__('dossiers.modal_markdown_content_placeholder')" />
                                    </div>
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




                    {{-- Squelette (TASK-1130 UX finale) : les fichiers arrivent
                         en asynchrone apres les dossiers/Articles rendus cote
                         serveur — sans lui, l'ecran affirmait « rien ici »
                         pendant le fetch. --}}
                    <div x-show="vue === 'documents' && filesLoading" x-cloak aria-hidden="true" class="mt-4 space-y-2">
                        <div class="flex items-center gap-3 rounded-xl border border-gray-100 px-4 py-3 dark:border-gray-700/60">
                            <div class="h-8 w-8 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-700"></div>
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="h-3 w-1/3 animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
                                <div class="h-2.5 w-1/5 animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 rounded-xl border border-gray-100 px-4 py-3 dark:border-gray-700/60">
                            <div class="h-8 w-8 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-700"></div>
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="h-3 w-2/5 animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
                                <div class="h-2.5 w-1/4 animate-pulse rounded bg-gray-100 dark:bg-gray-700"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl border border-gray-200 dark:border-gray-700" x-show="vue === 'documents' && viewMode === 'list' && (totalFiles > 0 || {{ ($driveFolders->count() + $dossier->dossierBlogPosts->count()) > 0 ? 'true' : 'false' }})">
                        <div class="hidden lg:block">
                            <div class="{{ $grilleDrive }} border-b border-gray-200 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                <div>{{ __('dossiers.col_name') }}</div>
                                <div>{{ __('dossiers.col_owner') }}</div>
                                <div>{{ $colonneEstType ? __('dossiers.col_type') : __('dossiers.col_share') }}</div>
                                <div>{{ __('dossiers.col_modified') }}</div>
                                <div>{{ __('dossiers.col_size') }}</div>
                                <div><span class="sr-only">{{ __('dossiers.col_name') }}</span></div>
                            </div>
                        </div>
                        <ul class="divide-y divide-gray-100 rounded-xl bg-white dark:divide-gray-700/60 dark:bg-gray-800">
                            {{-- Les dossiers d'abord, comme dans tout Drive. La
                                 recherche de la barre filtre aussi ces lignes,
                                 localement — memes regles pour les trois types. --}}
                            @foreach($driveFolders as $folder)
                                @php
                                    $estCibleDeplacement = $moveTargets->contains('id', $folder->getKey());
                                @endphp
                                <li class="{{ $grilleDrive }} px-4 py-2.5 transition first:rounded-t-xl last:rounded-b-xl hover:bg-amber-50/40 dark:hover:bg-amber-500/5"
                                    x-show="!searchQuery || {{ \Illuminate\Support\Js::from(mb_strtolower($folder->name)) }}.includes(searchQuery.toLowerCase())"
                                    @if($estCibleDeplacement)
                                    @dragover.prevent="onFolderDragOver('{{ $folder->getKey() }}')"
                                    @dragleave="onFolderDragLeave('{{ $folder->getKey() }}')"
                                    @drop.prevent="onFolderDrop('{{ $folder->getKey() }}')"
                                    :class="dragOverFolderId === '{{ $folder->getKey() }}'
                                        ? 'ring-2 ring-inset ring-indigo-400 bg-indigo-50/60 dark:bg-indigo-950/30'
                                        : (draggingFileId ? 'ring-1 ring-inset ring-dashed ring-indigo-300/70 dark:ring-indigo-700' : '')"
                                    @endif
                                    >
                                    @php
                                        // CAS B : un Dossier personnel seulement partage
                                        // ici (parent_id NULL, il vit ailleurs) — le
                                        // discriminant de la passe 4, rendu VISIBLE pour
                                        // que les menus differents soient previsibles.
                                        $estPartageIci = $folder->parent_id === null && $folder->owner !== null;
                                        // Une racine personnelle a MOI, listee dans « Mes
                                        // documents » : elle vit aussi ailleurs, mais elle
                                        // n'est pas « partagee par quelqu'un » — le
                                        // marqueur ne s'allume que pour le dossier d'un
                                        // autre.
                                        $estDunAutre = $estPartageIci && $folder->owner_id !== auth()->id();
                                        $nomProprietaire = $estDunAutre
                                            ? ($folder->owner->isDisplayableIn(currentOrganization()) ? $folder->owner->publicDisplayName() : __('profile.deactivated_user'))
                                            : null;
                                        $nbElements = $folder->files_count + $folder->dossier_blog_posts_count;
                                        // Une ligne qui porte sa propre gouvernance (une racine)
                                        // dit SON etat ; une ligne gouvernee par le Dossier
                                        // ouvert herite du sien.
                                        // Le proprietaire REEL de la ligne : le sien si elle
                                        // porte sa propre gouvernance (une racine), celui du
                                        // Dossier ouvert sinon.
                                        $proprietaireDuDossier = $estPartageIci ? $folder->owner : $governingDossier->owner;
                                        $proprietaireLibelle = $estPartageIci
                                            ? ($folder->owner_id === auth()->id() ? __('dossiers.owner_me') : ($nomProprietaire ?? '—'))
                                            : $proprietaireHerite;
                                        $partageDuDossier = ! $estPartageIci
                                            ? $partageHerite
                                            : ($folder->shared_with_loop_id
                                                ? __('dossiers.share_loop')
                                                : (($folder->dossier_members_count ?? 0) > 0 ? __('dossiers.share_shared') : __('dossiers.share_private')));
                                    @endphp
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="relative flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300" aria-hidden="true">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                                            @if($estDunAutre)
                                                <span class="absolute -bottom-0.5 -right-0.5 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-white ring-1 ring-amber-300 dark:bg-gray-800 dark:ring-amber-500/50">
                                                    <svg class="h-2 w-2 text-amber-600 dark:text-amber-300" fill="currentColor" viewBox="0 0 20 20"><path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/></svg>
                                                </span>
                                            @endif
                                        </span>
                                        <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="flex min-h-11 min-w-0 flex-1 flex-col justify-center">
                                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $folder->name }}</span>
                                            <span class="block truncate text-xs text-gray-500 lg:hidden dark:text-gray-400">{{ $partageDuDossier }} · <span data-folder-count="{{ $folder->getKey() }}" data-count="{{ $nbElements }}">{{ trans_choice('dossiers.drive_folder_items', $nbElements, ['count' => $nbElements]) }}</span>@if($estDunAutre) · {{ __('dossiers.drive_shared_by', ['name' => $nomProprietaire]) }}@endif</span>
                                        </a>
                                    </div>
                                    <div class="hidden min-w-0 items-center gap-2 lg:flex">
                                        <x-user-avatar :user="$proprietaireDuDossier" size="xs" />
                                        <span class="min-w-0 truncate text-xs text-gray-500 dark:text-gray-400">{{ $proprietaireLibelle }}</span>
                                    </div>
                                    <div class="{{ $colonneEstType ? $celluleLgDrive : $classePartage($partageDuDossier) }}">{{ $colonneEstType ? __('dossiers.drive_type_folder') : $partageDuDossier }}</div>
                                    <div class="{{ $celluleLgDrive }} tabular-nums">{{ $folder->updated_at?->isoFormat('L') }}</div>
                                    <div class="{{ $celluleLgDrive }} tabular-nums"><span data-folder-count="{{ $folder->getKey() }}" data-count="{{ $nbElements }}">{{ trans_choice('dossiers.drive_folder_items', $nbElements, ['count' => $nbElements]) }}</span></div>
                                    <div class="relative justify-self-end" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                        <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                                class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                aria-label="{{ $folder->name }}">
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                        </button>
                                        <div x-show="open" x-cloak @click.outside="open = false"
                                             class="absolute right-0 top-full z-30 mt-1 w-44 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                            <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_open') }}</a>
                                            @if($folder->parent_id !== null)
                                                {{-- CAS A : un vrai sous-dossier, reellement possede ici
                                                     (Boucle ou personnel) — pas seulement vu en passant. --}}
                                                @can('update', $folder)
                                                    <a href="{{ route('organization.dossiers.edit', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.rename') }}</a>
                                                @endcan
                                                @can('manageMembers', $folder)
                                                    <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $folder->getKey(), 'partage' => 1]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.share_tab') }}</a>
                                                @endcan
                                                @can('delete', $folder)
                                                    <button type="button" @click="open = false; openDeleteFolderModal('{{ $folder->getKey() }}', @js($folder->name))"
                                                            class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.drive_delete_folder') }}</button>
                                                @endcan
                                            @else
                                                {{-- CAS B : ce dossier vit ailleurs (l'espace personnel de
                                                     son proprietaire) — seulement partage avec cette Boucle.
                                                     Le retirer n'est jamais le supprimer. --}}
                                                @can('update', $folder)
                                                    <a href="{{ route('organization.dossiers.edit', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.rename') }}</a>
                                                @endcan
                                                @if($folder->shared_with_loop_id)
                                                @can('updateVisibility', $folder)
                                                    {{-- Confirmation legere (TASK-1130 UX finale) :
                                                         retirer un partage n'est pas supprimer, mais
                                                         un submit direct sur une surface d'equipe
                                                         restait une surprise. --}}
                                                    <button type="button" @click="open = false; openUnshareFolderModal('{{ $folder->getKey() }}', @js($folder->name), '{{ route('organization.dossiers.unshare', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}')"
                                                            class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_unshare_folder') }}</button>
                                                @endcan
                                                @endif
                                                @can('delete', $folder)
                                                    <button type="button" @click="open = false; openDeleteFolderModal('{{ $folder->getKey() }}', @js($folder->name))"
                                                            class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.drive_delete_folder_definitively') }}</button>
                                                @endcan
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach

                            {{-- Les Articles : identite editoriale, une seule
                                 apparition dans la surface. --}}
                            @foreach($dossier->dossierBlogPosts as $entry)
                                @php $post = $entry->blogPost; @endphp
                                @continue(! $post || ! $canView($post))
                                <li class="{{ $grilleDrive }} px-4 py-2.5 transition first:rounded-t-xl last:rounded-b-xl hover:bg-rose-50/40 dark:hover:bg-rose-500/5"
                                    x-show="!searchQuery || {{ \Illuminate\Support\Js::from(mb_strtolower($post->title)) }}.includes(searchQuery.toLowerCase())">
                                    @php
                                        $auteurArticle = $post->user?->isDisplayableIn(currentOrganization()) ? $post->user->publicDisplayName() : __('profile.deactivated_user');
                                        $motsArticle = str_word_count(strip_tags((string) $post->content));
                                        // La Serie dont CET Article est la racine, s'il y en a une.
                                        $serieDeLArticle = $seriesList->firstWhere('root_blog_post_id', $post->getKey());
                                        $elementsDeSerie = $serieDeLArticle ? $serieDeLArticle->items->count() + 1 : null;
                                    @endphp
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300" aria-hidden="true">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                                        </span>
                                        <a href="{{ $blogShowRoute($post) }}" class="flex min-h-11 min-w-0 flex-1 flex-col justify-center">
                                            <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $post->title }}</span>
                                            <span class="block truncate text-xs text-gray-500 lg:hidden dark:text-gray-400">{{ $partageHerite }} · {{ __('dossiers.drive_article_badge') }} · {{ $post->updated_at?->isoFormat('L') }}</span>
                                        </a>
                                    </div>
                                    <div class="hidden min-w-0 items-center gap-2 lg:flex">
                                        <x-user-avatar :user="$post->user" size="xs" />
                                        <span class="min-w-0 truncate text-xs text-gray-500 dark:text-gray-400">{{ $post->user_id === auth()->id() ? __('dossiers.owner_me') : $auteurArticle }}</span>
                                    </div>
                                    <div class="{{ $colonneEstType ? $celluleLgDrive : $classePartage($partageHerite) }}">{{ $colonneEstType ? __('dossiers.drive_article_badge') : $partageHerite }}</div>
                                    <div class="{{ $celluleLgDrive }} tabular-nums">{{ $post->updated_at?->isoFormat('L') }}</div>
                                    {{-- La taille d'un Article : le nombre d'elements
                                         quand il ouvre une Serie — c'est ce qu'on
                                         vient y lire —, son texte sinon. --}}
                                    <div class="{{ $celluleLgDrive }} tabular-nums">{{ $elementsDeSerie !== null
                                        ? trans_choice('dossiers.drive_folder_items', $elementsDeSerie, ['count' => $elementsDeSerie])
                                        : trans_choice('dossiers.article_words', $motsArticle, ['count' => number_format($motsArticle, 0, ',', ' ')]) }}</div>
                                    <div class="relative justify-self-end" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                        <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                                class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                aria-label="{{ $post->title }}">
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                        </button>
                                        <div x-show="open" x-cloak @click.outside="open = false"
                                             class="absolute right-0 top-full z-30 mt-1 w-48 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                            <a href="{{ $blogShowRoute($post) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_open') }}</a>
                                            @if($canManageArticles)
                                                <a href="{{ $blogEditRoute($post) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_edit_article') }}</a>
                                                {{-- Un Article ne se partage pas seul : le partage vit sur
                                                     le Dossier qui le contient, et l'entree le dit. --}}
                                                @unless($dossier->isPersonalDocumentsRoot())
                                                    <button type="button" @click="open = false; window.dispatchEvent(new CustomEvent('open-share-panel'))" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.share_the_folder') }}</button>
                                                @endunless
                                                <form method="POST" action="{{ route('organization.dossiers.articles.destroy', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'post' => $post->id]) }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.drive_remove_article') }}</button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach

                            {{-- Les fichiers : donnees JS, meme anatomie de ligne. --}}
                            <template x-for="file in sortedFiles" :key="file.id">
                                <li class="group/ligne {{ $grilleDrive }} px-4 py-2.5 transition first:rounded-t-xl last:rounded-b-xl hover:bg-gray-50 dark:hover:bg-gray-900/40 @if($canDeleteFiles && $moveTargets->isNotEmpty()) cursor-grab active:cursor-grabbing @endif"
                                    @if($canDeleteFiles && $moveTargets->isNotEmpty())
                                    draggable="true"
                                    @dragstart="onFileDragStart(file)"
                                    @dragend="onFileDragEnd()"
                                    :class="draggingFileId === file.id ? 'opacity-40' : ''"
                                    @endif
                                    >
                                    <div class="flex min-w-0 items-center gap-3">
                                    @if($canDeleteFiles && $moveTargets->isNotEmpty())
                                        <span class="-ml-2 hidden shrink-0 text-gray-300 group-hover/ligne:block dark:text-gray-600" aria-hidden="true" title="{{ __('dossiers.drive_drag_hint') }}">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                                        </span>
                                    @endif
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
                                    <button type="button" class="flex min-h-11 min-w-0 flex-1 flex-col justify-center text-left"
                                            @click="(file.mime_type?.startsWith('image/') || file.mime_type === 'application/pdf' || file.mime_type === 'text/plain' || file.mime_type === 'text/markdown') ? openPreview(file) : window.location = '{{ route('organization.dossiers.files.show', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', file.id)">
                                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="file.display_name || file.original_name"></span>
                                        <span class="block truncate text-xs text-gray-500 lg:hidden dark:text-gray-400" x-text="@js($partageHerite) + ' · ' + file.sizeFormatted + ' · ' + file.updatedAtFormatted"></span>
                                    </button>
                                    </div>
                                    <div class="hidden min-w-0 items-center gap-2 lg:flex">
                                        <img x-show="file.uploader?.avatar_url" :src="file.uploader?.avatar_url" alt="" aria-hidden="true"
                                             class="h-6 w-6 shrink-0 rounded-full object-cover">
                                        <span x-show="!file.uploader?.avatar_url"
                                              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[9px] font-semibold uppercase text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                              x-text="uploaderInitiales(file)"></span>
                                        <span class="min-w-0 truncate text-xs text-gray-500 dark:text-gray-400" x-text="uploaderLibelle(file)"></span>
                                    </div>
                                    @if($colonneEstType)
                                        <div class="{{ $celluleLgDrive }}" x-text="fileTypeLabel(file.mime_type)"></div>
                                    @else
                                        <div class="{{ $classePartage($partageHerite) }}">{{ $partageHerite }}</div>
                                    @endif
                                    <div class="{{ $celluleLgDrive }} tabular-nums" x-text="file.updatedAtFormatted"></div>
                                    <div class="{{ $celluleLgDrive }} tabular-nums" x-text="file.sizeFormatted"></div>
                                    <div class="relative justify-self-end" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                        <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                                class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                :aria-label="file.display_name || file.original_name">
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                        </button>
                                        <div x-show="open" x-cloak @click.outside="open = false"
                                             class="absolute right-0 top-full z-30 mt-1 w-44 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                            <button type="button" @click="open = false; openPreview(file)"
                                                    x-show="file.mime_type?.startsWith('image/') || file.mime_type === 'application/pdf' || file.mime_type === 'text/plain' || file.mime_type === 'text/markdown'"
                                                    class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.file_preview') }}</button>
                                            <a :href="'{{ route('organization.dossiers.files.show', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', file.id)"
                                               class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.file_download') }}</a>
                                            @if($canManageFiles)
                                                <button type="button" @click="open = false; openRenameModal(file)"
                                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.file_rename') }}</button>
                                            @endif
                                            @if($canDeleteFiles && $moveTargets->isNotEmpty())
                                                <button type="button" @click="open = false; openMoveModal(file)"
                                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.file_move') }}</button>
                                            @endif
                                            @unless($dossier->isPersonalDocumentsRoot())
                                                <button type="button" @click="open = false; window.dispatchEvent(new CustomEvent('open-share-panel'))"
                                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.share_the_folder') }}</button>
                                            @endunless
                                            @if($canDeleteFiles)
                                                <button type="button" @click="open = false; openDeleteModal(file)" :disabled="saving"
                                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.file_delete') }}</button>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Une recherche qui ne trouve rien le dit : sans cet
                         etat, la surface vide se lisait comme un dossier vide. --}}
                    <div x-show="vue === 'documents' && aucunResultat" x-cloak class="py-14 text-center">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="i18n.searchNoResults"></p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400" x-text="searchQuery"></p>
                    </div>

                    {{-- Grille (TASK-1130 passe 4) : memes trois boucles que la
                         liste juste au-dessus ($driveFolders, dossierBlogPosts,
                         sortedFiles) — aucune source de donnees separee, une
                         presentation alternative seulement. Le glisser-deposer
                         et le menu "..." fonctionnent a l'identique. --}}
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                         x-show="vue === 'documents' && viewMode === 'grid' && (totalFiles > 0 || {{ ($driveFolders->count() + $dossier->dossierBlogPosts->count()) > 0 ? 'true' : 'false' }})">
                        @foreach($driveFolders as $folder)
                            @php
                                $estCibleDeplacement = $moveTargets->contains('id', $folder->getKey());
                            @endphp
                            <div class="group relative flex flex-col items-center rounded-xl border border-gray-200 bg-white p-4 text-center transition hover:border-amber-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800"
                                 x-show="!searchQuery || {{ \Illuminate\Support\Js::from(mb_strtolower($folder->name)) }}.includes(searchQuery.toLowerCase())"
                                 @if($estCibleDeplacement)
                                 @dragover.prevent="onFolderDragOver('{{ $folder->getKey() }}')"
                                 @dragleave="onFolderDragLeave('{{ $folder->getKey() }}')"
                                 @drop.prevent="onFolderDrop('{{ $folder->getKey() }}')"
                                 :class="dragOverFolderId === '{{ $folder->getKey() }}'
                                     ? 'ring-2 ring-inset ring-indigo-400 bg-indigo-50/60 dark:bg-indigo-950/30'
                                     : (draggingFileId ? 'ring-1 ring-inset ring-dashed ring-indigo-300/70 dark:ring-indigo-700' : '')"
                                 @endif
                                 >
                                @php
                                    // Meme discriminant CAS B que la liste, meme marqueur.
                                    $estPartageIci = $folder->parent_id === null && $folder->owner !== null;
                                    $estDunAutre = $estPartageIci && $folder->owner_id !== auth()->id();
                                    $nomProprietaire = $estDunAutre
                                        ? ($folder->owner->isDisplayableIn(currentOrganization()) ? $folder->owner->publicDisplayName() : __('profile.deactivated_user'))
                                        : null;
                                    $nbElements = $folder->files_count + $folder->dossier_blog_posts_count;
                                @endphp
                                <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="flex w-full flex-col items-center gap-2">
                                    <span class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300" aria-hidden="true">
                                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                                        @if($estDunAutre)
                                            <span class="absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-white ring-1 ring-amber-300 dark:bg-gray-800 dark:ring-amber-500/50">
                                                <svg class="h-2.5 w-2.5 text-amber-600 dark:text-amber-300" fill="currentColor" viewBox="0 0 20 20"><path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/></svg>
                                            </span>
                                        @endif
                                    </span>
                                    <span class="line-clamp-2 w-full text-sm font-medium text-gray-900 dark:text-gray-100">{{ $folder->name }}</span>
                                    <span class="w-full truncate text-xs text-gray-500 dark:text-gray-400"><span data-folder-count="{{ $folder->getKey() }}" data-count="{{ $nbElements }}">{{ trans_choice('dossiers.drive_folder_items', $nbElements, ['count' => $nbElements]) }}</span>@if($estDunAutre) · {{ __('dossiers.drive_shared_by', ['name' => $nomProprietaire]) }}@endif</span>
                                </a>
                                <div class="absolute right-1.5 top-1.5" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                    <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                            class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-gray-700 dark:hover:text-gray-200 max-sm:opacity-100 sm:h-8 sm:w-8 sm:opacity-0"
                                            :class="open && 'opacity-100'"
                                            aria-label="{{ $folder->name }}">
                                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                    </button>
                                    <div x-show="open" x-cloak @click.outside="open = false"
                                         class="absolute right-0 top-full z-30 mt-1 w-44 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                        <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_open') }}</a>
                                        @if($folder->parent_id !== null)
                                            @can('update', $folder)
                                                <a href="{{ route('organization.dossiers.edit', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.rename') }}</a>
                                            @endcan
                                            @can('manageMembers', $folder)
                                                <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $folder->getKey(), 'partage' => 1]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.share_tab') }}</a>
                                            @endcan
                                            @can('delete', $folder)
                                                <button type="button" @click="open = false; openDeleteFolderModal('{{ $folder->getKey() }}', @js($folder->name))"
                                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.drive_delete_folder') }}</button>
                                            @endcan
                                        @else
                                            @can('update', $folder)
                                                <a href="{{ route('organization.dossiers.edit', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.rename') }}</a>
                                            @endcan
                                            @if($folder->shared_with_loop_id)
                                            @can('updateVisibility', $folder)
                                                <button type="button" @click="open = false; openUnshareFolderModal('{{ $folder->getKey() }}', @js($folder->name), '{{ route('organization.dossiers.unshare', ['organization' => $orgParam, 'dossier' => $folder->getKey()]) }}')"
                                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_unshare_folder') }}</button>
                                            @endcan
                                            @endif
                                            @can('delete', $folder)
                                                <button type="button" @click="open = false; openDeleteFolderModal('{{ $folder->getKey() }}', @js($folder->name))"
                                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.drive_delete_folder_definitively') }}</button>
                                            @endcan
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        @foreach($dossier->dossierBlogPosts as $entry)
                            @php $post = $entry->blogPost; @endphp
                            @continue(! $post || ! $canView($post))
                            <div class="group relative flex flex-col items-center rounded-xl border border-gray-200 bg-white p-4 text-center transition hover:border-rose-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800"
                                 x-show="!searchQuery || {{ \Illuminate\Support\Js::from(mb_strtolower($post->title)) }}.includes(searchQuery.toLowerCase())">
                                <a href="{{ $blogShowRoute($post) }}" class="flex w-full flex-col items-center gap-2">
                                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300" aria-hidden="true">
                                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                                    </span>
                                    <span class="line-clamp-2 w-full text-sm font-medium text-gray-900 dark:text-gray-100">{{ $post->title }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.drive_article_badge') }} · {{ $post->user?->publicDisplayName() ?? '—' }}</span>
                                </a>
                                <div class="absolute right-1.5 top-1.5" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                    <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                            class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-gray-700 dark:hover:text-gray-200 max-sm:opacity-100 sm:h-8 sm:w-8 sm:opacity-0"
                                            :class="open && 'opacity-100'"
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
                            </div>
                        @endforeach

                        <template x-for="file in sortedFiles" :key="file.id">
                            <div class="group relative flex flex-col items-center rounded-xl border border-gray-200 bg-white p-4 text-center transition hover:border-gray-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 @if($canDeleteFiles && $moveTargets->isNotEmpty()) cursor-grab active:cursor-grabbing @endif"
                                 @if($canDeleteFiles && $moveTargets->isNotEmpty())
                                 draggable="true"
                                 @dragstart="onFileDragStart(file)"
                                 @dragend="onFileDragEnd()"
                                 :class="draggingFileId === file.id ? 'opacity-40' : ''"
                                 @endif
                                 >
                                <button type="button" class="flex w-full flex-col items-center gap-2"
                                        @click="(file.mime_type?.startsWith('image/') || file.mime_type === 'application/pdf' || file.mime_type === 'text/plain' || file.mime_type === 'text/markdown') ? openPreview(file) : window.location = '{{ route('organization.dossiers.files.show', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', file.id)">
                                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl"
                                          :class="{
                                              'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400': file.mime_type === 'application/pdf',
                                              'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400': file.mime_type?.startsWith('image/'),
                                              'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-400': file.mime_type === 'application/msword' || file.mime_type?.includes('wordprocessingml'),
                                              'bg-green-100 text-green-600 dark:bg-green-900/40 dark:text-green-400': file.mime_type === 'text/csv' || file.mime_type === 'application/vnd.ms-excel' || file.mime_type?.includes('spreadsheetml'),
                                              'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400': file.mime_type === 'text/plain',
                                              'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400': file.mime_type === 'text/markdown',
                                              'bg-orange-100 text-orange-600 dark:bg-orange-900/40 dark:text-orange-400': file.mime_type === 'application/zip' || file.mime_type === 'application/x-zip-compressed',
                                          }">
                                        <svg x-show="file.mime_type === 'application/pdf'" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                        <svg x-show="file.mime_type?.startsWith('image/')" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        <svg x-show="file.mime_type === 'application/msword' || file.mime_type?.includes('wordprocessingml')" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <svg x-show="file.mime_type === 'text/csv' || file.mime_type === 'application/vnd.ms-excel' || file.mime_type?.includes('spreadsheetml')" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        <svg x-show="file.mime_type === 'text/plain'" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <svg x-show="file.mime_type === 'text/markdown'" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                                        <svg x-show="file.mime_type === 'application/zip' || file.mime_type === 'application/x-zip-compressed'" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                    </span>
                                    <span class="line-clamp-2 w-full text-sm font-medium text-gray-900 dark:text-gray-100" x-text="file.display_name || file.original_name"></span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400" x-text="fileTypeLabel(file.mime_type)"></span>
                                </button>
                                <div class="absolute right-1.5 top-1.5" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                    <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                            class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-gray-700 dark:hover:text-gray-200 max-sm:opacity-100 sm:h-8 sm:w-8 sm:opacity-0"
                                            :class="open && 'opacity-100'"
                                            :aria-label="file.display_name || file.original_name">
                                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                    </button>
                                    <div x-show="open" x-cloak @click.outside="open = false"
                                         class="absolute right-0 top-full z-30 mt-1 w-44 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                        <button type="button" @click="open = false; openPreview(file)"
                                                x-show="file.mime_type?.startsWith('image/') || file.mime_type === 'application/pdf' || file.mime_type === 'text/plain' || file.mime_type === 'text/markdown'"
                                                class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.file_preview') }}</button>
                                        <a :href="'{{ route('organization.dossiers.files.show', ['organization' => $orgParam, 'dossier' => $dossier->getKey(), 'file' => '__FILE_ID__']) }}'.replace('__FILE_ID__', file.id)"
                                           class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.file_download') }}</a>
                                        @if($canDeleteFiles && $moveTargets->isNotEmpty())
                                            <button type="button" @click="open = false; openMoveModal(file)"
                                                    class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.file_move') }}</button>
                                        @endif
                                        @if($canDeleteFiles)
                                            <button type="button" @click="open = false; openDeleteModal(file)" :disabled="saving"
                                                    class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.file_delete') }}</button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

@if($driveFolders->isEmpty() && $dossier->dossierBlogPosts->isEmpty())
                    <template x-if="vue === 'documents' && !filesLoading && files.length === 0 && totalFiles === 0">
                        <div class="mt-4 rounded-xl border border-dashed border-gray-300 px-5 py-6 text-center dark:border-gray-700">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.drive_empty_title') }}</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.drive_empty_desc') }}</p>
                        </div>
                    </template>
                    @endif

                    <p class="mt-2 text-right text-xs text-gray-400 dark:text-gray-500" x-show="vue === 'documents' && quota.used_bytes > 0" x-text="quotaLabel"></p>

                    <div class="mt-4 flex items-center justify-center gap-2" x-show="vue === 'documents' && lastPage > 1">
                        <button @click="loadFiles(currentPage - 1)" :disabled="currentPage <= 1"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">&laquo;</button>
                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="currentPage + ' / ' + lastPage"></span>
                        <button @click="loadFiles(currentPage + 1)" :disabled="currentPage >= lastPage"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-white disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">&raquo;</button>
                    </div>


                    {{-- Fichiers refuses avant meme l'envoi (poids, format,
                         doublon) : une reponse claire plutot qu'un silence. --}}
                    <template x-if="showUploadRejectModal && uploadRejects.length">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showUploadRejectModal = false; uploadRejects = []" @keydown.escape.window="showUploadRejectModal = false; uploadRejects = []" role="dialog" aria-modal="true" aria-labelledby="upload-reject-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-300" aria-hidden="true">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <h3 id="upload-reject-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.upload_rejected_title') }}</h3>
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.upload_rejected_body') }}</p>
                                        <ul class="mt-3 max-h-56 space-y-1.5 overflow-y-auto">
                                            <template x-for="(refus, i) in uploadRejects" :key="i">
                                                <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/50">
                                                    <span class="block truncate font-medium text-gray-900 dark:text-gray-100" x-text="refus.name"></span>
                                                    <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="refus.reason"></span>
                                                </li>
                                            </template>
                                        </ul>
                                    </div>
                                </div>
                                <div class="mt-6 flex justify-end">
                                    <button type="button" @click="showUploadRejectModal = false; uploadRejects = []" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">{{ __('dossiers.upload_rejected_ok') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Renommer un fichier : le libelle lu par les gens, pas
                         le fichier sur le disque. L'extension d'origine est
                         conservee par le serveur. --}}
                    <template x-if="showRenameModal && renameTarget">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showRenameModal = false" @keydown.escape.window="showRenameModal = false" role="dialog" aria-modal="true" aria-labelledby="rename-file-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="rename-file-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100"
                                    x-text="(i18n.renameTitle || '').replace(':name', renameTarget.display_name || renameTarget.original_name)"></h3>
                                <div class="mt-4">
                                    <label for="rename-file-input" class="block text-sm font-medium text-gray-700 dark:text-gray-300" x-text="i18n.renameLabel"></label>
                                    <input id="rename-file-input" type="text" x-model="renameValue" maxlength="255" @keydown.enter="confirmRename()"
                                           class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                </div>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button type="button" @click="showRenameModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.drive_cancel') }}</button>
                                    <button type="button" @click="confirmRename()" :disabled="!renameValue.trim() || saving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50">{{ __('dossiers.file_rename') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Move Modal (TASK-1130 passe 4) : le fallback accessible du
                         glisser-deposer, meme point d'entree (confirmMoveFile). --}}
                    <template x-if="showMoveModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeMoveModal()" @keydown.escape.window="closeMoveModal()" role="dialog" aria-modal="true" aria-labelledby="move-file-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="move-file-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.moveModalTitle.replace(':name', moveTarget?.display_name || moveTarget?.original_name || '')"></h3>
                                <ul class="mt-4 max-h-72 space-y-1 overflow-y-auto">
                                    <template x-for="target in moveTargets" :key="target.id">
                                        <li>
                                            <button type="button" @click="confirmMoveFile(target.id)" :disabled="saving"
                                                    class="flex w-full items-center gap-2 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                                                <svg class="h-4 w-4 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                                                <span x-show="target.isParent" class="shrink-0 text-gray-400" aria-hidden="true">&uarr;</span>
                                                <span x-text="target.isParent ? i18n.moveToParent : target.name"></span>
                                            </button>
                                        </li>
                                    </template>
                                    <li x-show="!moveTargets.length" class="px-3 py-2.5 text-sm text-gray-500 dark:text-gray-400" x-text="i18n.moveNoTargets"></li>
                                </ul>
                                <div class="mt-4 flex justify-end">
                                    <button @click="closeMoveModal()" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.moveCancel"></button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Ajouter un article existant (fonction DOSSIER, via
                         + Nouveau) : la recherche d'articles eligibles et le
                         rattachement existants, en modal leger. --}}
                    @if($canManageArticles)
                    <template x-if="showAttachArticleModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeAttachArticleModal()" @keydown.escape.window="closeAttachArticleModal()" role="dialog" aria-modal="true" aria-labelledby="attach-article-title">
                            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <div class="flex items-center justify-between">
                                    <h3 id="attach-article-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.fab_attach_article') }}</h3>
                                    <button type="button" @click="closeAttachArticleModal()" class="flex h-11 w-11 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="{{ __('dossiers.drive_cancel') }}">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.article_search_help') }}</p>
                                <input x-model="attachSearchQuery" @input.debounce.300ms="searchAttachArticles()" type="search" placeholder="{{ __('dossiers.article_search_placeholder') }}"
                                       class="mt-4 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                <ul class="mt-4 max-h-64 space-y-1 overflow-y-auto">
                                    <li x-show="attachSearching" class="px-3 py-3 text-center text-sm text-gray-400">…</li>
                                    <template x-for="article in attachSearchResults" :key="article.id">
                                        <li class="flex items-center gap-3 rounded-xl px-3 py-2">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300" aria-hidden="true">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                                            </span>
                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="article.title"></span>
                                            <button type="button" @click="attachExistingArticle(article)" :disabled="attachSaving"
                                                    class="shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">{{ __('dossiers.attach_article') }}</button>
                                        </li>
                                    </template>
                                    <li x-show="!attachSearching && attachSearchQuery.trim().length >= 2 && !attachSearchResults.length" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.attach_no_results') }}</li>
                                </ul>
                            </div>
                        </div>
                    </template>
                    @endif

                    {{-- Creer une serie (mode Serie) : un nom, un bouton — le
                         moteur existant (store, name seul) fait le reste.
                         Gate serveur : un lecteur ne recoit aucun markup de
                         gestion. --}}
                    @if($canManageSeries)
                    <template x-if="showCreateSerieModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showCreateSerieModal = false" @keydown.escape.window="showCreateSerieModal = false" role="dialog" aria-modal="true" aria-labelledby="create-serie-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="create-serie-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.series_mode_create') }}</h3>
                                <div class="mt-4">
                                    <label for="new-serie-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('dossiers.series_mode_name_label') }}</label>
                                    <input id="new-serie-name" type="text" x-model="newSerieName" maxlength="255" @keydown.enter="createSerie()"
                                           class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                </div>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button type="button" @click="showCreateSerieModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.drive_cancel') }}</button>
                                    <button type="button" @click="createSerie()" :disabled="!newSerieName.trim() || serieSaving" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50">{{ __('dossiers.modal_create') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Ajouter a la serie : les contenus DEJA presents dans le
                         Dossier — rien n'est duplique, un contenu n'appartient
                         qu'a une seule Serie (regle du moteur). --}}
                    <template x-if="showSerieAddModal && serieActive">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showSerieAddModal = false" @keydown.escape.window="showSerieAddModal = false" role="dialog" aria-modal="true" aria-labelledby="serie-add-title">
                            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <div class="flex items-center justify-between">
                                    <h3 id="serie-add-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.series_mode_add_title') }}</h3>
                                    <button type="button" @click="showSerieAddModal = false" class="flex h-11 w-11 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="{{ __('dossiers.drive_cancel') }}">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                <ul class="mt-4 max-h-80 space-y-1 overflow-y-auto">
                                    <template x-for="article in serieArticleCandidates" :key="'blog:' + article.id">
                                        <li class="flex items-center gap-3 rounded-xl px-3 py-2">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300" aria-hidden="true">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/></svg>
                                            </span>
                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="article.title"></span>
                                            <button type="button" @click="serieAdd('article', article.id, article.title)" :disabled="serieSaving"
                                                    class="shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">{{ __('dossiers.series_mode_add_short') }}</button>
                                        </li>
                                    </template>
                                    <template x-for="file in serieFileCandidates" :key="'file:' + file.id">
                                        <li class="flex items-center gap-3 rounded-xl px-3 py-2">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400" aria-hidden="true">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            </span>
                                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="file.display_name || file.original_name"></span>
                                            <button type="button" @click="serieAdd('file', file.id, file.display_name || file.original_name)" :disabled="serieSaving"
                                                    class="shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">{{ __('dossiers.series_mode_add_short') }}</button>
                                        </li>
                                    </template>
                                    <li x-show="!serieArticleCandidates.length && !serieFileCandidates.length" class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.series_mode_no_candidates') }}</li>
                                </ul>
                            </div>
                        </div>
                    </template>

                    {{-- Supprimer la serie : on dissout la classification
                         sequentielle — aucun Article, aucun fichier, aucun
                         contenu du Dossier n'est supprime. --}}
                    @php /* toujours sous @if($canManageSeries) ouvert plus haut */ @endphp
                    <template x-if="showSerieDeleteModal && serieActive">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showSerieDeleteModal = false" @keydown.escape.window="showSerieDeleteModal = false" role="dialog" aria-modal="true" aria-labelledby="serie-delete-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="serie-delete-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.content_series_delete_modal_title') }}</h3>
                                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100" x-text="serieActive.name"></p>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.content_series_delete_modal_body') }}</p>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button type="button" @click="showSerieDeleteModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.drive_cancel') }}</button>
                                    <button type="button" @click="deleteSerieActive()" :disabled="serieSaving" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50">{{ __('dossiers.series_mode_delete') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>
                    @endif

                    {{-- Unshare Folder Modal (TASK-1130 UX finale) : retirer un
                         partage n'est PAS supprimer — confirmation legere, ton
                         calme, bouton indigo et non rouge. Le PATCH part du
                         <form> reel, meme route qu'avant. --}}
                    <template x-if="showUnshareFolderModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeUnshareFolderModal()" @keydown.escape.window="closeUnshareFolderModal()" role="dialog" aria-modal="true" aria-labelledby="unshare-folder-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="unshare-folder-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.drive_unshare_confirm_title') }}</h3>
                                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100" x-text="unshareFolderTarget?.name"></p>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.drive_unshare_confirm_body') }}</p>
                                <form method="POST" :action="unshareFolderTarget?.action" class="mt-6 flex justify-end gap-3">
                                    @csrf
                                    @method('PATCH')
                                    <button type="button" @click="closeUnshareFolderModal()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.drive_cancel') }}</button>
                                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">{{ __('dossiers.drive_unshare_confirm_confirm') }}</button>
                                </form>
                            </div>
                        </div>
                    </template>

                    {{-- Delete Folder Modal (TASK-1130 passe 4, CAS A/B) : un
                         seul modal, deux entrees ("Supprimer" / "Supprimer
                         definitivement") qui appellent le meme geste — la
                         distinction se joue au menu, pas ici. --}}
                    <template x-if="showDeleteFolderModal">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeDeleteFolderModal()" @keydown.escape.window="closeDeleteFolderModal()" role="dialog" aria-modal="true" aria-labelledby="delete-folder-title">
                            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800" @click.stop>
                                <h3 id="delete-folder-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.folderDeleteTitle.replace(':name', deleteFolderTarget?.name || '')"></h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300" x-text="i18n.folderDeleteBody"></p>
                                <div class="mt-6 flex justify-end gap-3">
                                    <button @click="closeDeleteFolderModal()" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" x-text="i18n.folderDeleteCancel"></button>
                                    <button @click="confirmDeleteFolder()" :disabled="deletingFolder" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50" x-text="i18n.folderDeleteConfirm"></button>
                                </div>
                            </div>
                        </div>
                    </template>

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
                    {{-- Ferme la surcouche de depot par glisser, ouverte plus haut. --}}
                    </div>
                </section>
                </x-dossiers.module>
                {{-- Ferme la portee Alpine `dossierFilesCard`, ouverte avec le
                     module : elle englobe la sidebar ET la surface. --}}
                </div>
                @endif

        {{-- Nouveau dossier — un vrai enfant (parent_id), dans n'importe quel
             Dossier : Boucle ou prive, racine ou deja imbrique (TASK-1130
             passe 4). Poste sur le store() existant ; creer un enfant est un
             geste d'ecriture sur CE dossier, meme regle que d'y attacher un
             fichier ou un article. --}}
        @can('update', $dossier)
            <div x-data="{ open: false }" @open-new-folder.window="open = true" x-on:keydown.escape.window="open = false">
                <template x-if="open">
                    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="open = false" role="dialog" aria-modal="true" aria-labelledby="new-folder-title">
                        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800">
                            {{-- TASK-1130 passe 4 : ce modal s'affichait aussi
                                 dans un Dossier prive en parlant d'une Boucle
                                 absente — un langage Drive unique n'impose pas
                                 un seul texte partout ou la realite differe. --}}
                            <h3 id="new-folder-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __($governingDossier->isLoopDossier() ? 'dossiers.drive_new_folder_title' : 'dossiers.drive_new_folder_title_private') }}</h3>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $governingDossier->isLoopDossier() ? __('dossiers.drive_new_folder_desc') : __('dossiers.drive_new_folder_desc_private', ['name' => $dossier->name]) }}</p>
                            <form method="POST" action="{{ route('organization.dossiers.store', ['organization' => $orgParam]) }}" class="mt-4">
                                @csrf
                                <input type="hidden" name="parent_id" value="{{ $dossier->getKey() }}">
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
        @endcan

        {{-- « Partager » (TASK-1130 UX finale) : un seul mot, deux contenus,
             toujours resolus sur la racine gouvernante — jamais sur l'enfant
             precis, qui n'a ni owner_id ni dossier_members a lui. Le bouton
             vit desormais dans la barre du Drive ; ce bloc n'est plus que le
             modal qu'il ouvre (contenu fonctionnel inchange, en x-show pour
             que dossierMembersCard s'initialise au chargement comme avant). --}}
        @php
            $gouvernant = $governingDossier;
        @endphp
        {{-- `?partage=1` ouvre le panneau a l'arrivee : c'est le lien que porte
             « Gerer » depuis la vue Partages, pour aller du constat au geste
             sans chercher. --}}
        <div x-data="{ open: {{ request()->boolean('partage') ? 'true' : 'false' }} }" @open-share-panel.window="open = true" x-on:keydown.escape.window="open = false">
            <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-4"
                 @click.self="open = false" role="dialog" aria-modal="true" aria-labelledby="share-panel-title">
                <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 id="share-panel-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.share_tab') }}</h3>
                        <button type="button" @click="open = false" class="flex h-11 w-11 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="{{ __('dossiers.cancel') }}">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                @if($gouvernant->isLoopDossier())
                    {{-- Gouverne par une Boucle : ni root ni enfant n'a de
                         gestion parallele. La Boucle est la source de verite,
                         et c'est chez elle qu'on partage. --}}
                    @php
                        $registreRoles = app(\App\Support\Loops\LoopRoleRegistry::class);
                        $ordreRoles = [\App\Support\Loops\LoopRoleRegistry::OWNER => 0, \App\Support\Loops\LoopRoleRegistry::FACILITATOR => 1];
                        $accesBoucle = ($gouvernant->loop?->activeMembers ?? collect())
                            ->sortBy(fn ($m) => [$ordreRoles[$registreRoles->canonical($m->role)] ?? 2, $m->joined_at]);
                    @endphp
                    <section>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.share_loop_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.share_loop_body') }}</p>

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

                        @if($gouvernant->loop)
                            <a href="{{ route('organization.loops.show', ['organization' => $orgParam, 'loop' => $gouvernant->loop]) }}"
                               class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                {{ __('dossiers.share_manage_loop_members') }} →
                            </a>
                        @endif
                    </section>
                @else
                <section x-data="dossierMembersCard(@js([
                             'csrfToken' => csrf_token(),
                             // La racine gouvernante, toujours — un enfant n'a
                             // ni owner_id ni dossier_members a lui : y ajouter
                             // quelqu'un depuis un sous-dossier doit ecrire au
                             // meme endroit que depuis la racine (TASK-1130).
                             'dossierId' => $gouvernant->getKey(),
                             'orgParam' => $orgParam,
                             'ownerId' => $gouvernant->owner_id,
                              'ownerName' => $gouvernant->owner?->publicDisplayName() ?? __('profile.deactivated_user'),
                              'ownerInitial' => $ownerDisplayable ? strtoupper(substr($gouvernant->owner->first_name ?? $gouvernant->owner->name ?? '?', 0, 1)) : '?',
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

        {{-- Garde d'espace mobile : le FAB global est desormais neutralise sur
             ce module (voir components/mobile-fab), il ne reste que la barre de
             navigation basse a degager. --}}
        <div class="h-16 sm:hidden" aria-hidden="true"></div>

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
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.share_tab') }}</h2>
                </section>
            </div>
        </noscript>
    </x-page-container>
</x-app-layout>
