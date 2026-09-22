{{--
    TASK-1621 — la modale « Pour ou contre ? ».

    Elle EXPLIQUE avant d'armer. Deux choses doivent etre dites avant que le
    membre engage deux generations :
      1. sa question part a deux assistants INDEPENDANTS ;
      2. ils ne lisent PAS les Dossiers de la Boucle.

    Le second point est le plus important : sans lui, un membre qui pose une
    question sur un document de la Boucle recevrait deux reponses hors sol et
    conclurait que le produit ne sait pas lire ses fichiers. Consulter les
    Dossiers reste une fonctionnalite separee, et l'ecran le dit.

    Alpine seul, aucune requete : ouvrir ou fermer cette modale ne coute rien.
    L'activation, elle, passe par Livewire et n'est qu'un changement de mode.
--}}
<div x-data="{ ouvert: false }"
     x-on:bp-open-pour-contre.window="ouvert = true"
     x-on:keydown.escape.window="ouvert = false">

    <div x-show="ouvert" x-cloak
         class="fixed inset-0 z-[9995] flex items-end justify-center bg-gray-900/50 p-0 backdrop-blur-sm sm:items-center sm:p-4"
         x-on:click.self="ouvert = false"
         role="dialog" aria-modal="true" aria-labelledby="pour-contre-titre"
         data-pour-contre-modal>

        <div class="w-full max-w-md rounded-t-2xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-700 dark:bg-gray-800 sm:rounded-2xl"
             x-transition.opacity>

            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0-18 7.5 4.5M12 3 4.5 7.5m15 0-2.25 6.75a3 3 0 0 0 4.5 0zm-15 0L2.25 14.25a3 3 0 0 0 4.5 0z"/></svg>
                </span>
                <div class="min-w-0">
                    <h2 id="pour-contre-titre" class="text-base font-bold text-gray-900 dark:text-gray-50">
                        {{ __('loops.plugins_multi_ai_modal_title') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">
                        {{ __('loops.plugins_multi_ai_modal_promise') }}
                    </p>
                </div>
            </div>

            <p class="mt-4 rounded-xl bg-gray-50 p-3 text-xs leading-5 text-gray-600 dark:bg-gray-900/40 dark:text-gray-300"
               data-pour-contre-explain>
                {{ __('loops.plugins_multi_ai_modal_explain') }}
            </p>

            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="ouvert = false"
                        data-pour-contre-cancel
                        class="inline-flex items-center justify-center rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('loops.plugins_multi_ai_modal_cancel') }}
                </button>
                <button type="button"
                        wire:click="toggleMultiAiMode"
                        x-on:click="ouvert = false"
                        data-pour-contre-activate
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-teal-600 px-4 py-2.5 text-sm font-semibold text-teal-50 transition hover:bg-teal-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    {{ __('loops.plugins_multi_ai_modal_activate') }}
                </button>
            </div>
        </div>
    </div>
</div>
