{{--
    « Personnaliser ma Boucle » — le catalogue d'outils du propriétaire.

    Deux zones : « Mes outils » et « Ajouter des outils ». L'utilisateur n'a
    pas à comprendre « principal contre secondaire » : la phrase d'intro dit
    tout ce qu'il faut — mettez-en jusqu'à 3 en avant pour qu'ils
    apparaissent en premier — et l'étoile porte le geste. ★ mis en avant,
    ☆ actif. Le modèle métier de TASK-1124 est inchangé : `enabled` reste la
    vérité, trois mis en avant au plus.

    L'identité visuelle d'un outil ne disparaît pas une fois installé : les
    cartes actives gardent leur aperçu (le même composant, en plus compact).
    Le vocabulaire est celui d'un outil, jamais celui du moteur — un
    prérequis se dit en une phrase.

    Le feedback vit dans la carte du geste : un refus s'affiche sous le
    contrôle qui l'a provoqué (`error_tool`), une réussite fait luire la
    carte (`success_tool`). Le toast global du layout complète. Les
    formulaires se verrouillent au premier envoi (Alpine, déjà partout).

    Les quatre gestes passent par le service canonique (TASK-1124) : cet
    écran n'a aucune logique métier à lui.
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

    // La carte qui vient de changer se signale d'un halo, puis s'éteint.
    $halo = fn (string $cle) => session('success_tool') === $cle
        ? 'x-data="{glow:true}" x-init="setTimeout(() => glow = false, 2200)" :class="glow && \'ring-2 ring-emerald-400/70\'"'
        : '';

    // Une seule grille : les outils mis en avant d'abord, puis les actifs.
    $mesOutils = array_merge(
        array_map(fn ($o) => $o + ['featured' => true], $composition['primary']),
        array_map(fn ($o) => $o + ['featured' => false], $composition['secondary']),
    );
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('loops.owner_tools_title') }} — {{ $loop->name }}</x-slot>

    <x-page-container>
        <div class="mx-auto max-w-6xl">

            <a href="{{ route('organization.loops.show', ['organization' => $orgParam, 'loop' => $loop->id]) }}"
               class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← {{ $loop->name }}</a>

            <h1 class="mt-3 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.owner_tools_title') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('loops.owner_tools_intro') }}</p>

            {{-- ── Mes outils ────────────────────────────────────────────── --}}
            <section class="mt-10">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('loops.owner_tools_mine_title') }}</h2>
                    <span class="text-sm text-gray-400 dark:text-gray-500">
                        {{ trans_choice('loops.owner_tools_featured_count', count($composition['primary']), ['count' => count($composition['primary']), 'max' => $composition['max_primary']]) }}
                    </span>
                </div>
                <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                    {{ __('loops.owner_tools_mine_hint', ['max' => $composition['max_primary']]) }}
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($mesOutils as $outil)
                        <article {!! $halo($outil['key']) !!}
                                 class="flex flex-col overflow-hidden rounded-2xl border transition-shadow duration-700 {{ $outil['featured']
                                    ? 'border-indigo-200 bg-gradient-to-br from-indigo-50/80 via-white to-white shadow-sm dark:border-indigo-500/40 dark:from-indigo-950/40 dark:via-gray-800 dark:to-gray-800'
                                    : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800' }}">

                            {{-- L'outil garde son aperçu une fois installé :
                                 même composant, habit plus compact. --}}
                            <div class="border-b px-5 pb-3 pt-4 {{ $outil['featured'] ? 'border-indigo-100/70 dark:border-indigo-500/20' : 'border-gray-100 dark:border-gray-700/60' }}">
                                <x-loops.tool-preview :tool="$outil['key']" variant="strip" />
                            </div>

                            <div class="flex flex-1 flex-col p-5 pt-4">
                                <div class="flex items-center gap-2.5">
                                    <x-loops.card-icon :icon="$outil['icon'] ?? null" />
                                    <h3 class="flex-1 text-base font-bold text-gray-900 dark:text-gray-100">{{ $outil['label'] }}</h3>

                                    @if($outil['required'])
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                            {{ __('loops.tools_catalog_state_required') }}
                                        </span>
                                    @else
                                        {{-- ★ mis en avant, ☆ actif : presser
                                             l'étoile bascule, rien d'autre. --}}
                                        <form method="POST" action="{{ $actionUrl }}" x-data="{ s: false }"
                                              @submit="if (s) { $event.preventDefault(); return } s = true">
                                            @csrf
                                            <input type="hidden" name="action" value="{{ $outil['featured'] ? 'demote' : 'promote' }}">
                                            <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                            <button type="submit" :disabled="s" :class="s && 'opacity-50 cursor-wait'"
                                                    title="{{ $outil['featured'] ? __('loops.tools_demote') : __('loops.tools_promote') }}"
                                                    class="flex h-11 w-11 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 {{ $outil['featured']
                                                        ? 'text-indigo-500 hover:bg-indigo-100 dark:text-indigo-300 dark:hover:bg-indigo-500/20'
                                                        : 'text-gray-400 hover:bg-indigo-50 hover:text-indigo-500 dark:text-gray-500 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-300' }}">
                                                @if($outil['featured'])
                                                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg>
                                                @else
                                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/></svg>
                                                @endif
                                                <span class="sr-only">{{ $outil['featured'] ? __('loops.tools_demote') : __('loops.tools_promote') }} — {{ $outil['label'] }}</span>
                                            </button>
                                        </form>
                                    @endif
                                </div>

                                <p class="mt-2 flex-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $outil['description'] }}</p>

                                @if(session('error_tool') === $outil['key'])
                                    <p role="alert" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium leading-5 text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ session('error') }}</p>
                                @endif

                                {{-- Désactiver est un geste secondaire : un lien
                                     sobre, et le rappel que rien ne se perd. Un
                                     outil toujours présent n'en a pas — le
                                     proposer serait mentir. --}}
                                @unless($outil['required'])
                                    <div class="mt-4 border-t pt-3 {{ $outil['featured'] ? 'border-indigo-100/70 dark:border-indigo-500/20' : 'border-gray-100 dark:border-gray-700/60' }}">
                                        <form method="POST" action="{{ $actionUrl }}" x-data="{ s: false }"
                                              @submit="if (s) { $event.preventDefault(); return } s = true"
                                              class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                            @csrf
                                            <input type="hidden" name="action" value="disable">
                                            <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                            <button type="submit" :disabled="s" :class="s && 'opacity-50 cursor-wait'"
                                                    class="inline-flex min-h-11 items-center text-sm font-medium text-gray-500 underline-offset-2 transition hover:text-gray-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-gray-400 dark:hover:text-gray-200">
                                                {{ __('loops.owner_tools_remove') }}
                                            </button>
                                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('loops.tools_data_kept_note') }}</span>
                                        </form>
                                    </div>
                                @endunless
                            </div>
                        </article>
                    @endforeach

                    @if($mesOutils === [])
                        <p class="rounded-2xl border border-dashed border-gray-300 p-5 text-sm text-gray-500 sm:col-span-2 lg:col-span-3 dark:border-gray-700 dark:text-gray-400">
                            {{ __('loops.owner_tools_none_primary') }}
                        </p>
                    @endif
                </div>
            </section>

            {{-- ── Ajouter des outils ────────────────────────────────────── --}}
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
                            <article {!! $halo($outil['key']) !!}
                                     class="flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white transition-shadow duration-700 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 {{ $bloque ? 'opacity-75 hover:shadow-none' : '' }}">

                                {{-- L'aperçu ouvre la carte : voir l'outil avant
                                     de le lire. --}}
                                <div class="relative border-b border-gray-100 bg-gradient-to-b from-gray-50 to-white px-5 pb-4 pt-5 dark:border-gray-700/60 dark:from-gray-900/60 dark:to-gray-800 {{ $bloque ? 'grayscale' : '' }}">
                                    <x-loops.tool-preview :tool="$outil['key']" variant="band" />
                                    @if($bloque)
                                        <span class="absolute right-4 top-4 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                                            {{ __('loops.tools_catalog_state_blocked') }}
                                        </span>
                                    @endif
                                </div>

                                <div class="flex flex-1 flex-col p-5">
                                    <div class="flex items-center gap-2.5">
                                        <x-loops.card-icon :icon="$outil['icon'] ?? null" class="{{ $bloque ? 'grayscale' : '' }}" />
                                        <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">{{ $outil['label'] }}</h3>
                                    </div>
                                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $outil['description'] }}</p>

                                    @if(($outil['data_count'] ?? 0) > 0)
                                        <p class="mt-2 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                                            {{ trans_choice('loops.tools_catalog_has_content', $outil['data_count'], ['count' => $outil['data_count']]) }}
                                        </p>
                                    @endif

                                    @if($manquants->isNotEmpty())
                                        <p class="mt-2 text-sm leading-5 text-amber-700 dark:text-amber-300">
                                            {{ trans_choice('loops.owner_tools_requires', $manquants->count(), ['tools' => $manquants->implode(', ')]) }}
                                        </p>
                                    @elseif($conflits->isNotEmpty())
                                        <p class="mt-2 text-sm leading-5 text-amber-700 dark:text-amber-300">
                                            {{ __('loops.owner_tools_conflicts', ['tools' => $conflits->implode(', ')]) }}
                                        </p>
                                    @endif

                                    @if(session('error_tool') === $outil['key'])
                                        <p role="alert" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium leading-5 text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ session('error') }}</p>
                                    @endif

                                    <div class="flex-1"></div>
                                    <div class="mt-4">
                                        @if($bloque)
                                            <button type="button" disabled
                                                    class="inline-flex min-h-11 w-full cursor-not-allowed items-center justify-center rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                                {{ __('loops.tools_add_to_loop') }}
                                            </button>
                                        @else
                                            <form method="POST" action="{{ $actionUrl }}" x-data="{ s: false }"
                                                  @submit="if (s) { $event.preventDefault(); return } s = true">
                                                @csrf
                                                <input type="hidden" name="action" value="enable">
                                                <input type="hidden" name="tool" value="{{ $outil['key'] }}">
                                                <button type="submit" :disabled="s" :class="s && 'opacity-60 cursor-wait'"
                                                        class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">
                                                    <svg x-show="s" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"/></svg>
                                                    {{ __('loops.tools_add_to_loop') }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

        </div>
    </x-page-container>
</x-app-layout>
