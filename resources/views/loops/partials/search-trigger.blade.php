{{--
    TASK-1885 C9 (demande Cyril, 22/11) — la loupe, a GAUCHE des boutons
    Grille/Liste (bureau et smartphone). Le champ s'ouvre au clic ; Entree
    mene a la page de resultats.

    Personne membre d'aucune Boucle : pas de champ, un message (rien a
    chercher), plutot qu'une recherche toujours vide.

    Attend : $searchAction (URL de la page de resultats), $hasMemberLoops (bool),
    $compact (bool, smartphone).
--}}
@php($compact = $compact ?? false)
<div x-data="{ open: false, nudge: false }"
     @keydown.escape="open = false; nudge = false"
     @click.outside="nudge = false; if (! $refs.q?.value) open = false"
     class="relative flex items-center {{ $class ?? '' }}"
     data-loops-search-trigger{{ $compact ? '-mobile' : '' }}>
    <button type="button"
            @click="@if($hasMemberLoops) open = ! open; $nextTick(() => open && $refs.q.focus()) @else nudge = ! nudge @endif"
            :aria-expanded="(open || nudge).toString()"
            aria-label="{{ __('loops.search_open') }}" title="{{ __('loops.search_open') }}"
            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white text-gray-600 transition hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:text-indigo-400"
            :class="open && 'border-indigo-500 text-indigo-600 dark:text-indigo-400'">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
    </button>

    @if($hasMemberLoops)
        <form method="GET" action="{{ $searchAction }}" role="search"
              x-show="open" x-cloak
              x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
              class="{{ $compact ? 'absolute left-0 top-full z-30 mt-2 w-[min(20rem,calc(100vw-2rem))]' : 'ml-2' }}"
              data-loops-search-inline-form>
            <label class="relative block">
                <span class="sr-only">{{ __('loops.search_label') }}</span>
                <input x-ref="q" type="search" name="q" minlength="{{ \App\Support\Loops\LoopSearch::MIN }}" maxlength="{{ \App\Support\Loops\LoopSearch::MAX }}" required
                       placeholder="{{ __('loops.search_placeholder_short') }}"
                       class="h-10 {{ $compact ? 'w-full shadow-lg' : 'w-56 lg:w-64' }} rounded-lg border border-indigo-400 bg-white px-3 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-indigo-500 dark:bg-gray-800 dark:text-gray-100">
            </label>
        </form>
    @else
        {{-- Message « premium » : une bulle, pas une alerte. --}}
        <div x-show="nudge" x-cloak role="status"
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
             class="absolute {{ $compact ? 'left-0' : 'right-0' }} top-full z-30 mt-2 w-72 rounded-2xl border border-indigo-100 bg-white p-4 text-left shadow-xl dark:border-indigo-900 dark:bg-gray-800"
             data-loops-search-no-loop-nudge>
            <p class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-indigo-50 text-indigo-600 dark:bg-indigo-900/50 dark:text-indigo-300" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                </span>
                {{ __('loops.search_nudge_title') }}
            </p>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('loops.search_nudge_body') }}</p>
        </div>
    @endif
</div>
