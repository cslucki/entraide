{{-- TASK-1649 — le mode JSON (CDC 10.10).

     « L'UI ne doit jamais masquer qu'une modification JSON peut invalider le
     scenario. » C'est pourquoi l'avertissement se lit AVANT d'enregistrer, et
     pas apres.

     Le texte est l'unique source de verite (CDC 10.1) : il est rendu tel qu'il
     est stocke, jamais reformate a l'affichage. Un editeur qui reecrit
     discretement ce qu'on a tape fait deux verites.

     Une version CHARGEE s'ouvre quand meme, en lecture, et l'ecran dit
     pourquoi et vers quoi se tourner (CDC 8.6). Un champ grise sans
     explication passerait pour une panne. --}}
<x-admin-layout :title="$version->name">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.outils.scenarios.show', $version) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ __('admin.scenario_manager.editor_back') }}</a>

        {{-- La reciproque du lien que l'editeur visuel porte deja vers ici :
             les deux modes editent le MEME document, on doit pouvoir passer de
             l'un a l'autre dans les deux sens. --}}
        <a href="{{ route('admin.outils.scenarios.visual', $version) }}"
           data-open-visual
           class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ __('admin.scenario_manager.visual_title') }} &rarr;</a>
    </div>

    <header class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $version->name }}</h1>

        @if($version->isLoaded())
            <span data-state="loaded" class="rounded-full bg-indigo-100 px-2 py-1 text-xs font-semibold text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">{{ __('admin.scenario_manager.state_loaded') }}</span>
        @elseif($version->isValid())
            <span data-state="valid" class="rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-300">{{ __('admin.scenario_manager.state_valid') }}</span>
        @else
            <span data-state="draft" class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.state_draft') }}</span>
        @endif

        <span class="font-mono text-xs text-gray-400 dark:text-gray-500">{{ $version->scenario_key }} · v{{ $version->version }}</span>
    </header>

    @if(session('status'))
        <div class="mb-6 rounded-xl border border-green-300 bg-green-50 p-4 text-sm text-green-900 dark:border-green-800 dark:bg-green-900/20 dark:text-green-200">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <ul class="space-y-1 text-sm text-red-900 dark:text-red-200">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @unless($modifiable)
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
            <p class="text-sm text-amber-900 dark:text-amber-200">{{ __('admin.scenario_manager.editor_locked') }}</p>
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2">
            <form method="POST" action="{{ route('admin.outils.scenarios.update', $version) }}">
                @csrf
                @method('PUT')

                <label for="json" class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.editor_title') }}</label>

                @php
                    // Le texte AFFICHE, qui n'est pas toujours celui en base :
                    // apres un refus, `old()` rend la saisie rejetee. Compter
                    // les octets du document stocke afficherait alors
                    // « 1 234 octets » sous un texte de trois millions.
                    $texteAffiche = old('json', $version->json_source);
                @endphp

                <textarea id="json" name="json" rows="26" spellcheck="false" @disabled(! $modifiable)
                          class="mt-1 w-full rounded-lg border-gray-300 font-mono text-xs leading-relaxed disabled:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">{{ $texteAffiche }}</textarea>

                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    {{ __('admin.scenario_manager.editor_bytes', ['octets' => number_format(strlen($texteAffiche), 0, ',', ' '), 'maximum' => number_format(\App\Models\ScenarioManifestVersion::MAX_JSON_BYTES, 0, ',', ' ')]) }}
                </p>

                @if($modifiable)
                    {{-- CDC 10.10 : ne jamais masquer qu'une modification peut
                         invalider le scenario. L'avertissement se lit donc
                         AVANT le bouton, pas dans le message qui suit. --}}
                    <p class="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                        {{ __('admin.scenario_manager.editor_modified_warning') }}
                    </p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            {{ __('admin.scenario_manager.editor_save') }}
                        </button>
                    </div>
                @endif
            </form>

            <div class="mt-3 flex flex-wrap gap-2">
                @if($modifiable)
                    <form method="POST" action="{{ route('admin.outils.scenarios.validate', $version) }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/30">
                            {{ __('admin.scenario_manager.editor_validate') }}
                        </button>
                    </form>
                @endif

                <a href="{{ route('admin.outils.scenarios.export', $version) }}"
                   class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                    {{ __('admin.scenario_manager.editor_export') }}
                </a>
            </div>
        </section>

        <aside class="space-y-4">
            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.editor_errors') }}</h2>

                <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('admin.scenario_manager.preview_verdict') }} :
                    <span class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ is_scalar($verdict) ? $verdict : __('admin.scenario_manager.preview_verdict_none') }}</span>
                </p>

                @if($erreurs === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.editor_no_errors') }}</p>
                @else
                    {{-- CDC 10.10 : erreurs LOCALISEES. Le message d'abord, le
                         pointeur ensuite — c'est la phrase qui dit quoi
                         corriger, le chemin qui dit ou. --}}
                    <ul class="space-y-3">
                        @foreach(array_slice($erreurs, 0, $limite) as $erreur)
                            <li class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
                                <p class="text-sm text-red-900 dark:text-red-200">{{ $erreur['message'] ?? '' }}</p>
                                <p class="mt-1 break-all font-mono text-xs text-red-700 dark:text-red-400">{{ $erreur['path'] ?? '' }} · {{ $erreur['code'] ?? '' }}</p>
                            </li>
                        @endforeach
                    </ul>

                    @if(count($erreurs) > $limite)
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('admin.scenario_manager.preview_truncated', ['limit' => $limite, 'total' => count($erreurs)]) }}
                        </p>
                    @endif
                @endif
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.editor_duplicate') }}</h2>
                <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.editor_duplicate_hint') }}</p>

                <form method="POST" action="{{ route('admin.outils.scenarios.duplicate', $version) }}" class="space-y-2">
                    @csrf
                    <input name="scenario_key" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="64"
                           placeholder="{{ __('admin.scenario_manager.create_key') }}"
                           class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <input name="name" required maxlength="120"
                           placeholder="{{ __('admin.scenario_manager.create_name') }}"
                           class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <button type="submit" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('admin.scenario_manager.editor_duplicate') }}
                    </button>
                </form>
            </section>

            @if($modifiable)
                <section class="rounded-xl border border-red-200 bg-white p-4 dark:border-red-900 dark:bg-gray-800">
                    {{-- Une confirmation DEPLIANTE plutot qu'un `confirm()` :
                         une boite de dialogue du navigateur bloque la page, et
                         la question posee reste lisible ici apres coup. --}}
                    <details>
                        <summary class="cursor-pointer text-sm font-semibold text-red-700 dark:text-red-400">{{ __('admin.scenario_manager.editor_delete') }}</summary>

                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">{{ __('admin.scenario_manager.editor_delete_confirm') }}</p>

                        <form method="POST" action="{{ route('admin.outils.scenarios.destroy', $version) }}" class="mt-2">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">
                                {{ __('admin.scenario_manager.editor_delete') }}
                            </button>
                        </form>
                    </details>
                </section>
            @endif
        </aside>
    </div>
</x-admin-layout>
