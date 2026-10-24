{{--
    TASK-1695 — la fenetre « Partager ce fichier », en PARTIEL.

    Extraite de `dossiers/show.blade.php` pour servir AUSSI dans
    « Partages » : Cyril veut pouvoir ajouter des personnes sans revenir sur
    la page du Dossier. Une seconde copie du balisage aurait derive — c'est
    deja ce qui est arrive a l'idiome `display_name || original_name`, recopie
    a six endroits.

    ## Le contrat

    Le partiel ne connait PAS son hote. Il suppose seulement que le composant
    Alpine englobant expose :

    - `showShareModal`, `shareTarget`, `shareQuery`, `shareResults`,
      `shareCurrent`, `shareLoading`
    - `chercherMembres()`, `partagerAvec(u)`, `retirerPartage(s)`

    Deux composants l'honorent : `dossierFilesCard` (page du Dossier) et
    `fileSharePanel` (« Partages »). Les noms sont IDENTIQUES des deux cotes —
    c'est ce qui permet au meme balisage de servir sans parametre.
--}}
                    <template x-if="showShareModal && shareTarget">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                             @click.self="showShareModal = false" @keydown.escape.window="showShareModal = false"
                             role="dialog" aria-modal="true" aria-labelledby="share-file-title"
                             data-file-share-modal>
                            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 id="share-file-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.file_share_title') }}</h3>
                                        <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400" x-text="shareTarget.display_name || shareTarget.original_name"></p>
                                    </div>
                                    <button type="button" @click="showShareModal = false"
                                            class="-mr-1 -mt-1 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                            title="{{ __('dossiers.file_share_close') }}"
                                            aria-label="{{ __('dossiers.file_share_close') }}"
                                            data-file-share-close>
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>

                                <input type="search" x-model="shareQuery" @input.debounce.300ms="chercherMembres()"
                                       placeholder="{{ __('dossiers.file_share_search') }}"
                                       class="mt-4 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                       data-file-share-search>

                                <template x-if="shareResults.length > 0">
                                    <ul class="mt-2 max-h-40 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                        <template x-for="u in shareResults" :key="u.id">
                                            <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                                <span class="min-w-0 truncate text-gray-800 dark:text-gray-200">
                                                    {{-- TASK-1695 — le nom SEUL. L'adresse ne quitte plus le serveur
                                                         (voir DossierFileShareController::search) : rien a masquer ici,
                                                         il n'y a plus rien a afficher. --}}
                                                    <span x-text="((u.first_name || '') + ' ' + (u.name || '')).trim()"></span>
                                                </span>
                                                <button type="button" @click="partagerAvec(u)" :disabled="shareLoading"
                                                        class="shrink-0 rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                                        data-file-share-add>{{ __('dossiers.file_share') }}</button>
                                            </li>
                                        </template>
                                    </ul>
                                </template>

                                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('dossiers.file_share_current') }}</p>

                                <template x-if="shareCurrent.length === 0">
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.file_share_none') }}</p>
                                </template>

                                <ul class="mt-1 max-h-40 overflow-y-auto">
                                    <template x-for="s in shareCurrent" :key="s.id">
                                        <li class="flex items-center justify-between gap-3 py-1.5 text-sm" data-file-share-row>
                                            <span class="min-w-0 truncate text-gray-800 dark:text-gray-200">
                                                <span x-text="((s.first_name || '') + ' ' + (s.name || '')).trim()"></span>
                                            </span>
                                            <button type="button" @click="retirerPartage(s)" :disabled="shareLoading"
                                                    class="shrink-0 text-xs font-semibold text-red-600 hover:underline disabled:opacity-50 dark:text-red-400"
                                                    data-file-share-remove>{{ __('dossiers.file_share_remove') }}</button>
                                        </li>
                                    </template>
                                </ul>

                                {{-- « Fermer », et non « Annuler » : les partages
                                     s'appliquent IMMEDIATEMENT, au clic sur
                                     « Partager ». Il n'y a rien a annuler, et
                                     quelqu'un qui clique « Annuler » croirait
                                     avoir tout defait. --}}
                                <div class="mt-5 flex justify-end">
                                    <button type="button" @click="showShareModal = false"
                                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.file_share_close') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>
