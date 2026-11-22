{{-- TASK-1879 (demandes Cyril, 22/11) — la tuile Boucle d'une carte du Flux,
     en bas de la carte. Survol : indigo plein (l'item, lui, s'eclaircit).
     Pas de mot « Ouvrir » : la tuile entiere est le lien. --}}
<a href="{{ $lien }}" data-feed-loop-tile="{{ $boucle->id }}"
   aria-label="{{ __('feed.open_loop') }} : {{ $boucle->name }}"
   class="group/boucle flex items-center gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-3 py-2 transition hover:border-indigo-600 hover:bg-indigo-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-indigo-500 dark:hover:bg-indigo-600">
    @if($boucle->cover_image_url)
        <img src="{{ $boucle->cover_image_url }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover ring-2 ring-white dark:ring-gray-900">
    @else
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-white ring-2 ring-white transition group-hover/boucle:bg-white group-hover/boucle:text-indigo-600 dark:bg-indigo-500 dark:ring-gray-900" aria-hidden="true">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ config('navigation_icons.loops') }}"/></svg>
        </span>
    @endif
    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-indigo-950 group-hover/boucle:text-white dark:text-white">{{ $boucle->name }}</span>
    <span class="flex shrink-0 items-center gap-1 text-xs text-indigo-700 group-hover/boucle:text-indigo-100 dark:text-gray-300">
        @if($boucle->isPrivate())
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
            {{ __('loops.visibility_private') }}
        @else
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3M3.5 9h17M3.5 15h17"/></svg>
            {{ __('loops.visibility_public') }}
        @endif
    </span>
</a>
