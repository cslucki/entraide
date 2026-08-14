{{-- « + Nouveau » — le domicile stable de la creation (TASK-1130, reference
     canonique drive-v2). Rendu deux fois, jamais deux moteurs : la sidebar le
     porte au bureau, la ligne d'outils sur mobile ou la sidebar n'existe pas.
     Les deux instances pilotent le meme etat Alpine `showImportMenu` et les
     memes actions ; seule celle du bureau porte `x-ref="fabButton"`, la cible
     de retour de focus.

     @param bool   $avecRef        cette instance est-elle la cible de focus
     @param string $classesBouton  largeur/position propres au contexte
     @param string $ancrageMenu    alignement du panneau deroulant --}}
@php
    $avecRef ??= false;
    $classesBouton ??= '';
    $ancrageMenu ??= 'left-0';
@endphp

<div class="relative">
    <button @if($avecRef) x-ref="fabButton" @endif
            @click="showImportMenu = !showImportMenu" type="button"
            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 {{ $classesBouton }}">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
        {{ __('dossiers.fab_action') }}
    </button>

    <div x-show="showImportMenu" @click.away="showImportMenu = false" x-cloak x-transition
         class="absolute {{ $ancrageMenu }} z-30 mt-2 w-64 rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
        {{-- Section: Ajouter (TASK-1130 UX finale) : au bureau,
             Fichier/Photo/Video/Audio ouvraient le MEME selecteur — quatre
             entrees pour un geste. Une seule entree « Importer des fichiers » ;
             sur mobile, la capture camera est une capacite reellement distincte
             (capture="user"), elle garde ses actions explicites. --}}
        <div class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('dossiers.fab_section_add') }}</div>
        <button @click="showImportMenu = false; browseFiles()" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            {{ __('dossiers.fab_import_files') }}
        </button>
        @if($canManageArticles)
        {{-- Rattacher un Article existant au DOSSIER — une fonction Dossier,
             pas Serie (doctrine finale) : elle vit dans le flux d'ajout, plus
             dans un panneau. Moteur inchange (articles.store). --}}
        <button @click="showImportMenu = false; openAttachArticleModal()" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
            <svg class="h-5 w-5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/></svg>
            {{ __('dossiers.fab_attach_article') }}
        </button>
        @endif
        <button @click="showImportMenu = false; triggerMediaUpload('image')" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700 sm:hidden">
            <svg class="h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            {{ __('dossiers.fab_take_photo') }}
        </button>
        <button @click="showImportMenu = false; triggerMediaUpload('video')" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700 sm:hidden">
            <svg class="h-5 w-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            {{ __('dossiers.fab_record_video') }}
        </button>

        <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>

        {{-- Section: Creer --}}
        <div class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('dossiers.fab_section_create') }}</div>
        @can('update', $dossier)
            <button @click="showImportMenu = false; window.dispatchEvent(new CustomEvent('open-new-folder'))" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
                <svg class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                {{ __('dossiers.drive_new_folder') }}
            </button>
        @endcan
        <button @click="showImportMenu = false; openArticleModal()" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
            <svg class="h-5 w-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            {{ __('dossiers.fab_new_article') }}
        </button>
        <button @click="showImportMenu = false; openMdModal()" type="button" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700">
            <svg class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            {{ __('dossiers.fab_markdown_note') }}
        </button>
    </div>
</div>
