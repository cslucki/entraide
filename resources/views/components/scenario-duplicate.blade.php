@props(['version'])

{{-- TASK-1656 §16 a §19 — « Dupliquer », action de premier rang.

     UN SEUL composant, inclus par la bibliotheque ET par la fiche. Le §18 le
     demande explicitement, et pour une raison concrete : deux formulaires
     auraient fini par differer sur un champ — l'usage, la clef proposee, le
     libelle — et la difference n'aurait ete decouverte que par une copie
     incoherente selon l'endroit d'ou on l'avait lancee.

     Le backend est la primitive existante `ScenarioVersionWriter::duplicate()`,
     inchangee depuis T1649 : nouvelle clef, nouveau nom, retour a 1.0.0, DRAFT,
     `parent_id` en provenance, et AUCUN attribut systeme recopie.

     §19 : dupliquer une version CHARGEE est autorise, mais copie le Manifest
     ENREGISTRE, pas l'etat vivant de la sandbox. L'ecran doit le dire — sinon
     l'utilisateur croira avoir fige ce qu'il voit dans la sandbox, et c'est
     « Capturer » qui fait cela. --}}

@php
    // La clef proposee est calculee SERVEUR : si aucun script ne demarre, le
    // formulaire reste utilisable avec une valeur deja valide.
    $clefProposee = \Illuminate\Support\Str::limit(
        \Illuminate\Support\Str::slug('copie-de-'.$version->scenario_key),
        64,
        ''
    );
@endphp

<div x-data="{ ouvert: false, nom: @js(__('admin.scenario_manager.duplicate_name_default', ['name' => $version->name])) }"
     class="inline-block">
    <button type="button" x-on:click="ouvert = true"
            data-action="duplicate"
            data-scenario-key="{{ $version->scenario_key }}"
            class="inline-flex items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
        {{ __('admin.scenario_manager.card_duplicate') }}
    </button>

    <div x-cloak x-show="ouvert" x-on:keydown.escape="ouvert = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div x-on:click.outside="ouvert = false"
             data-panel="duplicate"
             class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ __('admin.scenario_manager.duplicate_title', ['name' => $version->name]) }}
            </h2>

            @if($version->isLoaded())
                {{-- §19 : la distinction qui evite le pire malentendu du produit. --}}
                <p class="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                    {{ __('admin.scenario_manager.duplicate_not_capture') }}
                </p>
            @endif

            <form method="POST" action="{{ route('admin.outils.scenarios.duplicate', $version) }}" class="mt-4 space-y-3">
                @csrf

                <div>
                    <label for="dup-name-{{ $version->id }}" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('admin.scenario_manager.duplicate_name') }}
                    </label>
                    <input id="dup-name-{{ $version->id }}" name="name" x-model="nom" required maxlength="120"
                           value="{{ __('admin.scenario_manager.duplicate_name_default', ['name' => $version->name]) }}"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>

                <div>
                    <label for="dup-key-{{ $version->id }}" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('admin.scenario_manager.duplicate_key') }}
                    </label>
                    <input id="dup-key-{{ $version->id }}" name="scenario_key" required
                           pattern="[a-z0-9]+(-[a-z0-9]+)*" minlength="3" maxlength="64"
                           value="{{ $clefProposee }}"
                           class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.create_key_hint') }}</p>
                </div>

                <div>
                    <label for="dup-usage-{{ $version->id }}" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ __('admin.scenario_manager.duplicate_usage') }}
                    </label>
                    <select id="dup-usage-{{ $version->id }}" name="usage"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        @foreach(\App\Models\ScenarioManifestVersion::USAGES as $usagePossible)
                            {{-- L'usage de la SOURCE par defaut, et modifiable. --}}
                            <option value="{{ $usagePossible }}" @selected($version->usage === $usagePossible)>{{ __('admin.scenario_manager.usage_'.$usagePossible) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ __('admin.scenario_manager.duplicate_submit') }}
                    </button>
                    <button type="button" x-on:click="ouvert = false"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        {{ __('admin.scenario_manager.duplicate_cancel') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
