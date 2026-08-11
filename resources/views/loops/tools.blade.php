{{--
    « Personnaliser ma Boucle » — l'écran du propriétaire.

    Trois zones, et rien d'autre : ce qui est mis en avant, ce qui est actif
    sans l'être, ce qu'on peut ajouter. Le vocabulaire est celui d'un outil,
    jamais celui du moteur : ni Card, ni socle, ni preset, ni clé de
    dépendance — un prérequis se dit en une phrase.

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
        <div class="mx-auto max-w-3xl">

            <a href="{{ route('organization.loops.show', ['organization' => $orgParam, 'loop' => $loop->id]) }}"
               class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← {{ $loop->name }}</a>

            <h1 class="mt-3 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.owner_tools_title') }}</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('loops.owner_tools_intro') }}</p>

            @if(session('success'))
                <p class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ session('success') }}</p>
            @endif
            @if(session('error'))
                <p class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ session('error') }}</p>
            @endif

            {{-- ── Zone 1 : les outils mis en avant ─────────────────────── --}}
            <section class="mt-8">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('loops.tools_primary_title') }}
                    <span class="ml-1 text-sm font-normal text-gray-400">{{ count($composition['primary']) }} / {{ $composition['max_primary'] }}</span>
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.owner_tools_primary_hint') }}</p>

                <div class="mt-4 space-y-3">
                    @foreach($composition['primary'] as $outil)
                        <div class="rounded-2xl border border-indigo-200 bg-white p-4 dark:border-indigo-900/60 dark:bg-gray-800">
                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $outil['label'] }}</p>
                            <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $outil['description'] }}</p>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="POST" action="{{ $actionUrl }}">
                                    @csrf
                                    <input type="hidden" name="action" value="demote">
                                    <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-gray-300 px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                        {{ __('loops.tools_demote') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach

                    @if($composition['primary'] === [])
                        <p class="rounded-2xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            {{ __('loops.owner_tools_none_primary') }}
                        </p>
                    @endif
                </div>
            </section>

            {{-- ── Zone 2 : les autres outils actifs ─────────────────────── --}}
            @if($composition['secondary'] !== [])
                <section class="mt-8">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.tools_secondary_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.owner_tools_secondary_hint') }}</p>

                    <div class="mt-4 space-y-3">
                        @foreach($composition['secondary'] as $outil)
                            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-bold text-gray-900 dark:text-gray-100">
                                    {{ $outil['label'] }}
                                    @if($outil['required'])
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ __('loops.owner_tools_always_on') }}</span>
                                    @endif
                                </p>
                                <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $outil['description'] }}</p>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ $actionUrl }}">
                                        @csrf
                                        <input type="hidden" name="action" value="promote">
                                        <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700">
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
                                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-gray-300 px-4 text-sm font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                                {{ __('loops.owner_tools_remove') }}
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ── Zone 3 : ce qu'on peut ajouter ────────────────────────── --}}
            @if($composition['available'] !== [])
                <section class="mt-8">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.owner_tools_add_title') }}</h2>

                    <div class="mt-4 space-y-3">
                        @foreach($composition['available'] as $outil)
                            @php
                                // Le moteur rend des clés ; on les dit en toutes
                                // lettres. Aucun nouveau moteur de dépendances.
                                $manquants = collect($outil['blockers']['missing'] ?? [])->map($nomDe);
                                $conflits = collect($outil['blockers']['conflicting'] ?? [])->map($nomDe);
                                $bloque = $manquants->isNotEmpty() || $conflits->isNotEmpty();
                            @endphp
                            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                                <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $outil['label'] }}</p>
                                <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $outil['description'] }}</p>

                                @if($manquants->isNotEmpty())
                                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                                        {{ trans_choice('loops.owner_tools_requires', $manquants->count(), ['tools' => $manquants->implode(', ')]) }}
                                    </p>
                                @elseif($conflits->isNotEmpty())
                                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                                        {{ __('loops.owner_tools_conflicts', ['tools' => $conflits->implode(', ')]) }}
                                    </p>
                                @endif

                                <div class="mt-3">
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
                                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                                {{ __('loops.owner_tools_add') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

        </div>
    </x-page-container>
</x-app-layout>
