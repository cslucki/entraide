{{--
    TASK-1864 — l'action principale d'une Boucle du catalogue, partagee par la
    carte (grille) et la ligne (liste). Extraite TELLE QUELLE de
    `catalog-card` : membre -> ouvrir, entree libre -> rejoindre, sur demande
    -> demander (ou « demande envoyee »), sinon -> decouvrir. Aucune regle
    n'est ajoutee ici ; seule la largeur change d'une presentation a l'autre.

    Attend : $item, $loopShowHref, $loopJoinAction, et $ctaWidth
    ('w-full' pour la carte, '' pour la ligne).
--}}
@php($ctaWidth = $ctaWidth ?? 'w-full')
@if($item->is_member)
    <a href="{{ $loopShowHref($item) }}"
       class="relative z-10 inline-flex {{ $ctaWidth }} items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
        {{ __('loops.cta_open_workspace') }}
    </a>
@elseif($item->isOpenAccess())
    <form method="POST" action="{{ $loopJoinAction($item) }}" class="relative z-10">
        @csrf
        <button type="submit"
                class="inline-flex {{ $ctaWidth }} items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
            {{ __('loops.cta_join') }}
        </button>
    </form>
@elseif($item->isRequestAccess())
    @if($item->has_pending_request)
        <span class="relative z-10 inline-flex {{ $ctaWidth }} items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            {{ __('loops.cta_pending') }}
        </span>
    @else
        <a href="{{ $loopShowHref($item) }}"
           class="relative z-10 inline-flex {{ $ctaWidth }} items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-indigo-200 px-3 py-2 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/20">
            {{ __('loops.cta_request') }}
        </a>
    @endif
@else
    <a href="{{ $loopShowHref($item) }}"
       class="relative z-10 inline-flex {{ $ctaWidth }} items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-500 transition hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-700">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
        </svg>
        {{ __('loops.cta_discover') }}
    </a>
@endif
