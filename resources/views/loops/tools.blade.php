{{--
    « Personnaliser ma Boucle » — le catalogue d'outils du propriétaire.

    Trois zones, et rien d'autre : ce qui est mis en avant, ce qui est actif
    sans l'être, ce qu'on peut ajouter. Le vocabulaire est celui d'un outil,
    jamais celui du moteur : ni Card, ni socle, ni preset, ni clé de
    dépendance — un prérequis se dit en une phrase.

    TASK-1127 : l'écran devient un catalogue. Chaque outil a son icône, sa
    teinte et — pour ceux qu'on peut ajouter — un aperçu de ce à quoi il
    ressemble. La hiérarchie se lit sans légende : les outils mis en avant
    portent un liseré indigo, les actifs sont des cartes pleines, les
    disponibles montrent leur aperçu et un bouton Ajouter, les bloqués
    l'expliquent en toutes lettres.

    Les quatre gestes passent par le service canonique (TASK-1124) : cet écran
    n'a aucune logique métier à lui.
--}}
@php
    $orgParam = $organizationRouteParam;
    $actionUrl = route('organization.loops.tools.update', ['organization' => $orgParam, 'loop' => $loop->id]);

    // Les noms humains des outils, pour dire un prérequis en toutes lettres
    // plutôt qu'en clé de catalogue.
    $nomDe = function (string $cle) use ($composition) {
        foreach (['primary', 'secondary', 'available', 'frame'] as $zone) {
            foreach ($composition[$zone] ?? [] as $outil) {
                if ($outil['key'] === $cle) {
                    return $outil['label'];
                }
            }
        }
        return $cle;
    };
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('loops.owner_tools_title') }} — {{ $loop->name }}</x-slot>

    <x-page-container>
        <div class="mx-auto max-w-6xl">

            <a href="{{ route('organization.loops.show', ['organization' => $orgParam, 'loop' => $loop->id]) }}"
               class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← {{ $loop->name }}</a>

            <h1 class="mt-3 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.owner_tools_title') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('loops.owner_tools_intro') }}</p>

            @if(session('success'))
                <p class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ session('success') }}</p>
            @endif
            @if(session('error'))
                <p class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ session('error') }}</p>
            @endif

            {{-- ── Zone 1 : les outils mis en avant ─────────────────────── --}}
            <section class="mt-10">
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ __('loops.tools_primary_title') }}
                    <span class="ml-1 text-sm font-normal text-gray-400">{{ count($composition['primary']) }} / {{ $composition['max_primary'] }}</span>
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.owner_tools_primary_hint') }}</p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($composition['primary'] as $outil)
                        <article class="flex flex-col rounded-2xl border-2 border-indigo-300 bg-white p-5 shadow-sm dark:border-indigo-500/50 dark:bg-gray-800">
                            <div class="flex items-start justify-between gap-3">
                                <x-loops.card-icon :icon="$outil['icon'] ?? null" size="lg" />
                                <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-semibold text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300">
                                    <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5l2.6 5.9 6.4.6-4.8 4.3 1.4 6.3L12 16.3l-5.6 3.3 1.4-6.3-4.8-4.3 6.4-.6z"/></svg>
                                    {{ __('loops.tools_catalog_state_primary') }}
                                </span>
                            </div>

                            <h3 class="mt-3 text-base font-bold text-gray-900 dark:text-gray-100">{{ $outil['label'] }}</h3>
                            <p class="mt-1 flex-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $outil['description'] }}</p>

                            <form method="POST" action="{{ $actionUrl }}" class="mt-4">
                                @csrf
                                <input type="hidden" name="action" value="demote">
                                <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-gray-300 px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                    {{ __('loops.tools_demote') }}
                                </button>
                            </form>
                        </article>
                    @endforeach

                    @if($composition['primary'] === [])
                        <p class="rounded-2xl border border-dashed border-gray-300 p-5 text-sm text-gray-500 sm:col-span-2 lg:col-span-3 dark:border-gray-700 dark:text-gray-400">
                            {{ __('loops.owner_tools_none_primary') }}
                        </p>
                    @endif
                </div>
            </section>

            {{-- ── Zone 2 : les autres outils actifs ─────────────────────── --}}
            @if($composition['secondary'] !== [])
                <section class="mt-10">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('loops.tools_secondary_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.owner_tools_secondary_hint') }}</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($composition['secondary'] as $outil)
                            <article class="flex flex-col rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <div class="flex items-start justify-between gap-3">
                                    <x-loops.card-icon :icon="$outil['icon'] ?? null" size="lg" />
                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                        {{ $outil['required'] ? __('loops.tools_catalog_state_required') : __('loops.tools_catalog_state_active') }}
                                    </span>
                                </div>

                                <h3 class="mt-3 text-base font-bold text-gray-900 dark:text-gray-100">{{ $outil['label'] }}</h3>
                                <p class="mt-1 flex-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $outil['description'] }}</p>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ $actionUrl }}">
                                        @csrf
                                        <input type="hidden" name="action" value="promote">
                                        <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">
                                            {{ __('loops.tools_promote') }}
                                        </button>
                                    </form>

                                    {{-- Un outil toujours présent n'a pas de bouton
                                         « Retirer » : le proposer serait mentir. --}}
                                    @unless($outil['required'])
                                        <form method="POST" action="{{ $actionUrl }}">
                                            @csrf
                                            <input type="hidden" name="action" value="disable">
                                            <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-gray-300 px-4 text-sm font-semibold text-gray-600 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                                {{ __('loops.owner_tools_remove') }}
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ── Zone 3 : le catalogue de ce qu'on peut ajouter ────────── --}}
            @if($composition['available'] !== [])
                <section class="mt-10">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('loops.owner_tools_add_title') }}</h2>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($composition['available'] as $outil)
                            @php
                                // Le moteur rend des clés ; on les dit en toutes
                                // lettres. Aucun nouveau moteur de dépendances.
                                $manquants = collect($outil['blockers']['missing'] ?? [])->map($nomDe);
                                $conflits = collect($outil['blockers']['conflicting'] ?? [])->map($nomDe);
                                $bloque = $manquants->isNotEmpty() || $conflits->isNotEmpty();
                            @endphp
                            <article class="flex flex-col rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800 {{ $bloque ? 'opacity-80' : '' }}">
                                <div class="flex items-start justify-between gap-3">
                                    <x-loops.card-icon :icon="$outil['icon'] ?? null" size="lg" class="{{ $bloque ? 'grayscale' : '' }}" />
                                    @if($bloque)
                                        <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                                            {{ __('loops.tools_catalog_state_blocked') }}
                                        </span>
                                    @endif
                                </div>

                                <h3 class="mt-3 text-base font-bold text-gray-900 dark:text-gray-100">{{ $outil['label'] }}</h3>
                                <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $outil['description'] }}</p>

                                <x-loops.tool-preview :tool="$outil['key']" class="mt-3" />

                                @if(($outil['data_count'] ?? 0) > 0)
                                    <p class="mt-3 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                                        {{ trans_choice('loops.tools_catalog_has_content', $outil['data_count'], ['count' => $outil['data_count']]) }}
                                    </p>
                                @endif

                                @if($manquants->isNotEmpty())
                                    <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">
                                        {{ trans_choice('loops.owner_tools_requires', $manquants->count(), ['tools' => $manquants->implode(', ')]) }}
                                    </p>
                                @elseif($conflits->isNotEmpty())
                                    <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">
                                        {{ __('loops.owner_tools_conflicts', ['tools' => $conflits->implode(', ')]) }}
                                    </p>
                                @endif

                                <div class="mt-4 flex-1"></div>
                                <div>
                                    @if($bloque)
                                        <button type="button" disabled
                                                class="inline-flex min-h-11 cursor-not-allowed items-center rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                            {{ __('loops.owner_tools_add') }}
                                        </button>
                                    @else
                                        <form method="POST" action="{{ $actionUrl }}">
                                            @csrf
                                            <input type="hidden" name="action" value="enable">
                                            <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">
                                                {{ __('loops.owner_tools_add') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

        </div>
    </x-page-container>
</x-app-layout>
