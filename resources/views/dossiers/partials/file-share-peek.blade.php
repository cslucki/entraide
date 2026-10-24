{{--
    TASK-1695 — l'apercu « Partage avec », en PARTIEL.

    Premier temps de l'ergonomie en deux temps : avec QUI, et arreter. Extrait
    de `dossiers/show.blade.php` pour servir AUSSI dans « Partages », ou Cyril
    veut le meme geste sur le nom des destinataires.

    Meme contrat que `file-share-modal` : le partiel ne connait pas son hote,
    il suppose `showPeekModal`, `shareTarget`, `shareCurrent`, `shareLoading`,
    `retirerPartage()` et `passerAuPartageComplet()`. Les deux composants qui
    le rendent — `dossierFilesCard` et `fileSharePanel` — emploient les MEMES
    noms.
--}}
                    {{-- TASK-1695 (point 1 de Cyril) — PREMIER temps : avec qui,
                         et arreter.

                         Clique depuis la colonne « Partage ». On repond a « c'est
                         partage avec qui ? » SANS traverser la fenetre complete et
                         son formulaire de recherche. Le second temps n'arrive que
                         si l'on veut ajouter quelqu'un.

                         Meme `shareTarget` et meme `shareCurrent` que la grande
                         fenetre : deux etats separes auraient pu diverger — retirer
                         quelqu'un ici sans que l'autre le sache. --}}
                    <template x-if="showPeekModal && shareTarget">
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                             @click.self="showPeekModal = false" @keydown.escape.window="showPeekModal = false"
                             role="dialog" aria-modal="true" aria-labelledby="share-peek-title"
                             data-file-share-peek-modal>
                            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 id="share-peek-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.share_peek_title') }}</h3>
                                        <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400" x-text="shareTarget.display_name || shareTarget.original_name"></p>
                                    </div>
                                    <button type="button" @click="showPeekModal = false"
                                            class="-mr-1 -mt-1 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                            aria-label="{{ __('dossiers.file_share_close') }}">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>

                                <ul class="mt-4 max-h-56 overflow-y-auto">
                                    <template x-for="s in shareCurrent" :key="s.id">
                                        <li class="flex items-center justify-between gap-3 py-2 text-sm" data-file-share-peek-row>
                                            <span class="flex min-w-0 items-center gap-2">
                                                {{-- Le visage s'il existe, sinon les initiales
                                                     DESSINEES ICI : `avatar_url` retombe sur un
                                                     service tiers, a qui on enverrait le nom de
                                                     la personne. --}}
                                                <template x-if="s.avatar_url">
                                                    <img :src="s.avatar_url" alt="" class="h-7 w-7 shrink-0 rounded-full object-cover ring-1 ring-black/5">
                                                </template>
                                                <template x-if="!s.avatar_url">
                                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[11px] font-semibold uppercase text-indigo-700 ring-1 ring-black/5 dark:bg-indigo-900/50 dark:text-indigo-200"
                                                          x-text="((s.first_name || s.name || '?').trim()[0] || '?')"></span>
                                                </template>
                                                <span class="min-w-0 truncate text-gray-800 dark:text-gray-200"
                                                      x-text="((s.first_name || '') + ' ' + (s.name || '')).trim()"></span>
                                            </span>
                                            <button type="button" @click="retirerPartage(s)" :disabled="shareLoading"
                                                    class="shrink-0 text-xs font-semibold text-red-600 hover:underline disabled:opacity-50 dark:text-red-400"
                                                    data-file-share-peek-remove>{{ __('dossiers.file_share_remove') }}</button>
                                        </li>
                                    </template>
                                </ul>

                                {{-- TASK-1695 (5a) — les Boucles aussi : l'apercu repond a
                                     « partage avec qui ? », et une Boucle est un destinataire
                                     comme un autre. Sans cette liste, la colonne dirait
                                     « Partage » et le panneau « personne ». --}}
                                <template x-if="shareLoopShares.length > 0">
                                    <ul class="max-h-32 overflow-y-auto border-t border-gray-100 dark:border-gray-700">
                                        <template x-for="s in shareLoopShares" :key="s.id">
                                            <li class="flex items-center justify-between gap-3 py-2 text-sm" data-file-share-peek-loop-row>
                                                <span class="flex min-w-0 items-center gap-2 text-gray-800 dark:text-gray-200">
                                                    <svg class="h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><circle cx="16.5" cy="15.5" r="3.2"/><path d="M4.5 19c.6-2.6 2.4-4 4.5-4M19.5 8.5C19 6 17.2 4.8 15.4 4.8"/></svg>
                                                    <span class="min-w-0 truncate" x-text="s.name"></span>
                                                </span>
                                                <button type="button" @click="retirerPartageBoucle(s)" :disabled="shareLoading"
                                                        class="shrink-0 text-xs font-semibold text-red-600 hover:underline disabled:opacity-50 dark:text-red-400">{{ __('dossiers.file_share_remove') }}</button>
                                            </li>
                                        </template>
                                    </ul>
                                </template>

                                <template x-if="shareCurrent.length === 0 && shareLoopShares.length === 0">
                                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.file_share_none') }}</p>
                                </template>

                                <div class="mt-5 flex items-center justify-between gap-3">
                                    <button type="button" @click="passerAuPartageComplet()"
                                            class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                                            data-file-share-peek-more>{{ __('dossiers.share_peek_add') }}</button>
                                    <button type="button" @click="showPeekModal = false"
                                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.file_share_close') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>
