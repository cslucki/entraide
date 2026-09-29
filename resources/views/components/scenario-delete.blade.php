@props(['version', 'versionsCount' => 1])

{{-- TASK-1656 §14 et §15 — « Supprimer », et un libelle qui dit LA VERITE.

     Le backend supprime UNE LIGNE de version
     (`ScenarioVersionWriter::delete()`), pas un scenario entier. Une clef de
     scenario peut porter plusieurs versions : la Capture cree `1.1.0` sous la
     MEME clef. Le libelle est donc CONTEXTUEL, calcule sur le nombre reel de
     versions que porte la clef :

     - une seule version  -> « Supprimer le scenario »  (c'est vrai : il ne
       restera rien de ce scenario) ;
     - plusieurs versions -> « Supprimer cette version » (et le sous-texte dit
       combien d'autres survivent).

     Dire « supprimer le scenario » devant deux versions serait un mensonge
     d'interface — l'utilisateur croirait avoir tout efface.

     §14 : pour une version CHARGEE, le backend refuse. L'action n'est pas
     MASQUEE en silence : le bouton devient une phrase qui dit quoi faire
     d'abord. Cacher un geste sans expliquer laisse chercher. --}}

@php
    $plusieurs = $versionsCount > 1;
    $libelle = $plusieurs
        ? __('admin.scenario_manager.delete_version')
        : __('admin.scenario_manager.delete_scenario');
    $question = $plusieurs
        ? __('admin.scenario_manager.delete_confirm_version', ['version' => $version->version, 'name' => $version->name])
        : __('admin.scenario_manager.delete_confirm_scenario', ['name' => $version->name]);
    $portee = $plusieurs
        ? __('admin.scenario_manager.delete_scope_version', ['count' => $versionsCount - 1])
        : __('admin.scenario_manager.delete_scope_scenario');
@endphp

@if($version->isLoaded())
    {{-- Refus EXPLIQUE, pas action absente. --}}
    <p data-delete="blocked"
       class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-gray-700/40 dark:text-gray-300">
        {{ __('admin.scenario_manager.delete_blocked_loaded') }}
    </p>
@else
    <div x-data="{ ouvert: false }" class="inline-block">
        <button type="button" x-on:click="ouvert = true"
                data-action="delete"
                data-scope="{{ $plusieurs ? 'version' : 'scenario' }}"
                class="inline-flex items-center rounded-lg border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/20">
            {{ $libelle }}
        </button>

        <div x-cloak x-show="ouvert" x-on:keydown.escape="ouvert = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div x-on:click.outside="ouvert = false"
                 data-panel="delete"
                 class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $question }}</h2>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">{{ $portee }}</p>

                {{-- POST/DELETE + CSRF (§15) : une suppression n'est jamais un
                     GET, sinon un lien visite l'executerait. --}}
                <form method="POST" action="{{ route('admin.outils.scenarios.destroy', $version) }}" class="mt-4 flex items-center gap-2">
                    @csrf
                    @method('DELETE')

                    <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                        {{ __('admin.scenario_manager.delete_submit') }}
                    </button>
                    <button type="button" x-on:click="ouvert = false"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        {{ __('admin.scenario_manager.duplicate_cancel') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@endif
