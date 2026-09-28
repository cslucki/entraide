{{-- TASK-1653 — capturer l'etat actuel.

     Cet ecran repond a UNE question : qu'est-ce qui a change dans la sandbox
     depuis son chargement ? Il n'ecrit rien. La creation de la nouvelle version
     est un SECOND geste, explicite, avec son propre bouton.

     Le vocabulaire est celui du produit : ni UUID, ni digest, ni clef interne.
     Les stable keys ne servent que de repli quand un objet n'a pas de nom. --}}
<x-admin-layout :title="__('admin.scenario_manager.capture_title')">
    <div class="mb-4">
        <a href="{{ route('admin.outils.scenarios.show', $version) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ __('admin.scenario_manager.editor_back') }}</a>
    </div>

    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.scenario_manager.capture_title') }}</h1>
    <p class="mt-1 mb-6 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.capture_intro') }}</p>

    @if($errors->any())
        <div data-capture-errors class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <ul class="space-y-1 text-sm text-red-900 dark:text-red-200">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- D'ou l'on part, et vers quoi l'on va. --}}
    <section class="mb-6 grid gap-4 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-3 dark:border-gray-700 dark:bg-gray-800">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.capture_source') }}</p>
            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $version->name }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">v{{ $version->version }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.capture_sandbox') }}</p>
            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100" data-sandbox>{{ $sandbox?->name ?? '—' }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $sandbox?->slug }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.capture_proposed') }}</p>
            <p class="mt-1 text-sm font-semibold text-indigo-700 dark:text-indigo-300" data-suggestion>{{ $suggestion ?? '—' }}</p>
        </div>
    </section>

    {{-- Les obstacles d'abord : s'il y en a, rien d'autre n'a d'importance. --}}
    @if($blockers !== [])
        <section data-capture-blocked class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <h2 class="text-sm font-semibold text-red-900 dark:text-red-200">{{ __('admin.scenario_manager.capture_blocked') }}</h2>
            <p class="mt-1 text-xs text-red-800 dark:text-red-300">{{ __('admin.scenario_manager.capture_blocked_hint') }}</p>

            <ul class="mt-3 space-y-2">
                @foreach($blockers as $blocker)
                    <li data-blocker class="rounded-lg bg-white/70 p-3 text-sm text-red-900 dark:bg-gray-900/40 dark:text-red-200">
                        <span class="font-semibold">{{ __('admin.scenario_manager.capture_family_'.str_replace('.', '_', $blocker['famille'])) }}</span>
                        <span class="ml-1 text-xs opacity-75">· {{ $blocker['raison'] }}</span>
                        <p class="mt-1 text-xs">{{ $blocker['detail'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @elseif($diff?->estVide())
        {{-- Rien n'a bouge : on ne cree PAS une version pour incrementer un
             numero. Le bouton n'existe simplement pas. --}}
        <section data-capture-empty class="rounded-xl border border-gray-300 bg-gray-50 p-6 text-center dark:border-gray-600 dark:bg-gray-800">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.capture_no_change') }}</p>
        </section>
    @else
        @php $totaux = $diff->totaux(); @endphp

        {{-- Niveau 1 : le resume. Trois chiffres, et on sait de quoi il retourne. --}}
        <section class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.capture_summary') }}</h2>

            <div class="flex flex-wrap gap-4 text-sm">
                <span data-total-added class="font-semibold text-green-700 dark:text-green-400">+{{ $totaux['added'] }} {{ __('admin.scenario_manager.capture_added') }}</span>
                <span data-total-changed class="font-semibold text-amber-700 dark:text-amber-400">~{{ $totaux['changed'] }} {{ __('admin.scenario_manager.capture_changed') }}</span>
                <span data-total-removed class="font-semibold text-red-700 dark:text-red-400">&minus;{{ $totaux['removed'] }} {{ __('admin.scenario_manager.capture_removed') }}</span>
            </div>
        </section>

        {{-- Niveau 2 : le detail, famille par famille, replie par defaut. --}}
        <section class="mb-6 space-y-3">
            @foreach($diff->famillesModifiees() as $famille => $mesure)
                <details data-family="{{ $famille }}" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <summary class="cursor-pointer text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('admin.scenario_manager.capture_family_'.str_replace('.', '_', $famille)) }}
                        <span class="ml-2 text-xs font-normal">
                            @if($mesure['added'] > 0)<span data-added class="text-green-700 dark:text-green-400">+{{ $mesure['added'] }}</span>@endif
                            @if($mesure['changed'] > 0)<span data-changed class="ml-1 text-amber-700 dark:text-amber-400">~{{ $mesure['changed'] }}</span>@endif
                            @if($mesure['removed'] > 0)<span data-removed class="ml-1 text-red-700 dark:text-red-400">&minus;{{ $mesure['removed'] }}</span>@endif
                        </span>
                    </summary>

                    <ul class="mt-3 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($mesure['objets'] as $objet)
                            <li data-object="{{ $objet['statut'] }}" class="py-2">
                                <span @class([
                                    'mr-2 text-xs font-bold',
                                    'text-green-700 dark:text-green-400' => $objet['statut'] === 'added',
                                    'text-amber-700 dark:text-amber-400' => $objet['statut'] === 'changed',
                                    'text-red-700 dark:text-red-400' => $objet['statut'] === 'removed',
                                ])>
                                    {{ ['added' => '+', 'changed' => '~', 'removed' => '−'][$objet['statut']] ?? '' }}
                                </span>
                                <span class="text-sm text-gray-900 dark:text-gray-100">{{ $objet['libelle'] }}</span>

                                @if($objet['champs'] !== [])
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('admin.scenario_manager.capture_fields') }} : {{ implode(', ', $objet['champs']) }}
                                    </p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </section>

        {{-- Le SECOND geste, et il est humain. --}}
        <section class="rounded-xl border border-indigo-300 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
            <form method="POST" action="{{ route('admin.outils.scenarios.capture.store', $version) }}" data-form="capture-create" class="space-y-3">
                @csrf

                <label class="block max-w-xs">
                    <span class="text-sm font-medium text-indigo-900 dark:text-indigo-200">{{ __('admin.scenario_manager.capture_version_label') }}</span>
                    <input type="text" name="version" value="{{ old('version', $suggestion) }}" required maxlength="20"
                           pattern="\d+\.\d+\.\d+"
                           class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                </label>

                <p class="max-w-lg text-xs text-indigo-800 dark:text-indigo-300">{{ __('admin.scenario_manager.capture_version_hint') }}</p>

                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    {{ __('admin.scenario_manager.capture_create', ['version' => $suggestion]) }}
                </button>
            </form>
        </section>
    @endif
</x-admin-layout>
