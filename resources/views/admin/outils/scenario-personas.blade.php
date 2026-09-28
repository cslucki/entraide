{{-- TASK-1654 — « Voir en tant que persona ».

     Cet ecran ne montre QUE des personas empruntables : l'eligibilite est
     tranchee par `ScenarioPersonaAccess`, la meme autorite que l'entree
     interroge. Il ne peut donc pas proposer un choix qui serait refuse.

     Aucun mot de passe, aucun jeton, aucune empreinte : rien de tel n'est
     affiche ici, parce que rien de tel n'est necessaire pour entrer. --}}
<x-admin-layout :title="__('admin.scenario_manager.personas_title')">
    <div class="mb-4">
        <a href="{{ route('admin.outils.scenarios.show', $version) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ __('admin.scenario_manager.editor_back') }}</a>
    </div>

    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.scenario_manager.personas_title') }}</h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        {{ __('admin.scenario_manager.personas_intro', ['sandbox' => $sandbox->name]) }}
    </p>

    {{-- L'avertissement est AVANT la liste, pas dans une confirmation qu'on
         clique sans lire. Le mode persona ecrit reellement dans la sandbox :
         ce n'est pas un apercu. --}}
    <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
        <p class="font-semibold">{{ __('admin.scenario_manager.personas_warning_title') }}</p>
        <p class="mt-1">{{ __('admin.scenario_manager.personas_warning_body') }}</p>
    </div>

    @if($personas->isEmpty())
        <p class="mt-8 rounded-xl border border-gray-200 bg-white p-6 text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
            {{ __('admin.scenario_manager.personas_empty') }}
        </p>
    @else
        <ul class="mt-8 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($personas as $persona)
                <li class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-center gap-3">
                        @if($persona->avatar)
                            <img src="{{ Storage::disk('public')->url($persona->avatar) }}"
                                 alt=""
                                 class="h-10 w-10 shrink-0 rounded-full object-cover">
                        @else
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200">
                                {{ Str::upper(Str::substr($persona->full_name ?: $persona->name, 0, 1)) }}
                            </span>
                        @endif
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $persona->full_name ?: $persona->name }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $sandbox->admin_id === $persona->id
                                    ? __('admin.scenario_manager.personas_role_admin')
                                    : __('admin.scenario_manager.personas_role_member') }}
                            </p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.outils.scenarios.personas.enter', $version) }}" data-form="persona-enter">
                        @csrf
                        <input type="hidden" name="persona_id" value="{{ $persona->id }}">
                        <button type="submit"
                                class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-indigo-700"
                                data-persona-enter="{{ $persona->id }}">
                            {{ __('admin.scenario_manager.personas_enter', ['name' => $persona->full_name ?: $persona->name]) }}
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</x-admin-layout>
