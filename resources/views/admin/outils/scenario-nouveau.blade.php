{{-- TASK-1649 — creer un scenario (CDC 9.1 a 9.3).

     Les trois points de depart aboutissent au MEME resultat : un DRAFT. Seule
     la provenance du texte change. C'est pourquoi ils vivent dans un seul
     formulaire plutot que dans trois ecrans : trois ecrans laisseraient croire
     a trois objets differents.

     Aucune donnee metier n'est creee ici. Le monde d'un scenario ne nait qu'au
     Load. --}}
<x-admin-layout :title="__('admin.scenario_manager.create_title')">
    <div class="mb-4">
        <a href="{{ route('admin.outils.scenarios') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ __('admin.scenario_manager.preview_back') }}</a>
    </div>

    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.scenario_manager.create_title') }}</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_subtitle') }}</p>

    @if($errors->any())
        <div class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <ul class="space-y-1 text-sm text-red-900 dark:text-red-200">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.outils.scenarios.store') }}" enctype="multipart/form-data"
          class="space-y-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="scenario_key" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_key') }}</label>
                <input id="scenario_key" name="scenario_key" value="{{ old('scenario_key') }}" required
                       pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="64"
                       class="mt-1 w-full rounded-lg border-gray-300 font-mono text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.create_key_hint') }}</p>
            </div>

            <div>
                <label for="name" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_name') }}</label>
                <input id="name" name="name" value="{{ old('name') }}" required maxlength="120"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>

            <div>
                <label for="locale" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_locale') }}</label>
                <select id="locale" name="locale" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="fr" @selected(old('locale', 'fr') === 'fr')>fr</option>
                    <option value="en" @selected(old('locale') === 'en')>en</option>
                </select>
            </div>

            {{-- La bibliotheque filtre sur QUATRE usages. Un formulaire qui en
                 figerait un seul rendrait trois valeurs du filtre inatteignables. --}}
            <div>
                <label for="usage" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_usage') }}</label>
                <select id="usage" name="usage" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach(\App\Models\ScenarioManifestVersion::USAGES as $usagePossible)
                        <option value="{{ $usagePossible }}" @selected(old('usage', \App\Models\ScenarioManifestVersion::USAGE_QA) === $usagePossible)>{{ __('admin.scenario_manager.usage_'.$usagePossible) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <fieldset>
            <legend class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_mode') }}</legend>

            <div class="mt-2 space-y-3">
                @foreach(['vide' => 'blank', 'coller' => 'paste', 'fichier' => 'file'] as $valeur => $cle)
                    <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input type="radio" name="mode" value="{{ $valeur }}" @checked(old('mode', 'vide') === $valeur)
                               class="mt-1 border-gray-300 text-indigo-600">
                        <span>
                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.scenario_manager.create_mode_'.$cle) }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_mode_'.$cle.'_hint') }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        {{-- Les deux champs restent VISIBLES quel que soit le mode : les
             masquer en JavaScript ferait dependre la saisie d'un script, et
             une page dont le script ne demarre pas deviendrait inutilisable.
             C'est le bouton radio qui decide, et le serveur qui l'applique. --}}
        <div>
            <label for="json" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_mode_paste') }}</label>
            <textarea id="json" name="json" rows="8" spellcheck="false"
                      class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('json') }}</textarea>
        </div>

        <div>
            <label for="fichier" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.create_mode_file') }}</label>
            <input id="fichier" name="fichier" type="file" accept="application/json,.json"
                   class="mt-1 block w-full text-sm text-gray-700 dark:text-gray-300">
        </div>

        <p class="rounded-lg bg-blue-50 p-3 text-xs text-blue-900 dark:bg-blue-900/20 dark:text-blue-200">
            {{ __('admin.scenario_manager.create_blank_note') }}
        </p>

        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            {{ __('admin.scenario_manager.create_submit') }}
        </button>
    </form>
</x-admin-layout>
