{{--
    Confirmation d'archivage ou de reactivation.

    Modale Alpine et non `confirm()` : la question n'est pas « etes-vous sur »,
    c'est « voici ce qui est conserve, et voici le seul cas ou cela merite qu'on
    s'arrete » — la derniere Boucle active de l'Organization. Une alerte native
    ne sait rien dire de tout cela.

    Teleportee dans <body> : la barre de titre du workspace cree un bloc
    conteneur qui neutraliserait `fixed inset-0`, defaut rencontre en TASK-1085.
--}}
@php($isArchived = $currentLoop->isArchived())

<div x-data="{ open: false }" x-on:open-loop-archive.window="open = true">
    <template x-teleport="body">
        <div x-show="open" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-900/50 p-4"
             x-on:keydown.escape.window="open = false"
             x-on:click.self="open = false"
             role="dialog" aria-modal="true"
             aria-label="{{ $isArchived ? __('loops.reactivate_confirm_title') : __('loops.archive_confirm_title') }}">

            <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">

                <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">
                    {{ $isArchived ? __('loops.reactivate_confirm_title') : __('loops.archive_confirm_title') }}
                </h2>

                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    {{ $isArchived ? __('loops.reactivate_confirm_body') : __('loops.archive_confirm_body') }}
                </p>

                @unless($isArchived)
                    <p class="mt-3 rounded-xl bg-gray-50 px-3 py-2 text-xs leading-5 text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                        {{ __('loops.archive_confirm_impact', [
                            'members' => $impact['members'] ?? 0,
                            'messages' => $impact['messages'] ?? 0,
                            'cards' => $impact['cards'] ?? 0,
                        ]) }}
                    </p>

                    @if($impact['last_active'] ?? false)
                        {{-- Le seul cas qui merite une confirmation renforcee. On
                             previent, on n'interdit pas : c'est la decision de la
                             personne qui dirige l'Organization, pas la notre. --}}
                        <p class="mt-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold leading-5 text-amber-900 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200">
                            {{ __('loops.archive_confirm_last') }}
                        </p>
                    @endif
                @endunless

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" x-on:click="open = false"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('loops.archive_confirm_cancel') }}
                    </button>

                    <form method="POST"
                          action="{{ $isArchived
                              ? $_loopRoute('reactivate', ['loop' => $currentLoop])
                              : $_loopRoute('archive', ['loop' => $currentLoop]) }}">
                        @csrf
                        <button type="submit"
                                class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700">
                            {{ $isArchived ? __('loops.reactivate_confirm_cta') : __('loops.archive_confirm_cta') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
