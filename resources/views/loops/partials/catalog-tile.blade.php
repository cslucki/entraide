{{--
    TASK-1866 — une Boucle du catalogue en mode GRILLE (tuiles denses, maquette N).

    Memes regles que la ligne de la liste et que l'ancienne carte : meme
    condition de filtre (`CatalogFilter::xShow`), meme pastille de role ou
    badge d'acces, meme crayon sous `@can('update')`, meme signal « Actif
    recemment » (dernier message de moins de 7 jours, contrat de
    LoopActivityTrackingTest). Le reste — accroche, proprietaires, derniers
    messages, membres, action d'adhesion — vit dans le tiroir, servi par
    `loops.preview` (T1865) : la page initiale n'en contient rien.

    Les deux liens sont de VRAIS liens vers la Boucle. Depuis TASK-1885 C2,
    l'image y mene toujours ; le titre, sans JavaScript ou avec un clic modifie
    (Ctrl, Cmd, Maj, molette), aussi. Seul le clic normal SUR LE TITRE est
    intercepte par `openLoopPreview()` (loops/partials/preview-drawer).

    Attend : $item, $loopShowHref, $loopEditHref, $loopPreviewHref.
--}}
@php
    $lastActivityAt = $item->last_message_at ? \Carbon\Carbon::parse($item->last_message_at) : null;
    $isRecentlyActive = $lastActivityAt && $lastActivityAt->gt(now()->subDays(7));
    $isHighlighted = ($highlightedLoopId ?? null) === $item->id;
    $itemType = app(\App\Support\Loops\LoopTypeRegistry::class)->resolve($item->type);
@endphp
<article
    data-type="{{ $itemType }}"
    data-loops-catalog-tile="{{ $item->id }}"
    x-show="{{ \App\Support\Loops\CatalogFilter::xShow($item) }}"
    class="group relative min-w-0"
>
    {{-- TASK-1885 C2 (demande Cyril, 22/11) — l'IMAGE ouvre la Boucle
         directement, le TITRE ouvre le tiroir. Deux liens freres vers la meme
         adresse : seul le titre est intercepte par `openLoopPreview()`.
         L'image est retiree de l'ordre de tabulation (meme destination que
         le bouton « Ouvrir » du tiroir, joignable au clavier par le titre). --}}
    <a href="{{ $loopShowHref($item) }}"
       data-loops-tile-cover-link
       tabindex="-1"
       aria-hidden="true"
       class="block rounded-2xl">
        <div @class([
                'overflow-hidden rounded-2xl border transition duration-200 group-hover:-translate-y-0.5 group-hover:shadow-md motion-reduce:transition-none motion-reduce:group-hover:translate-y-0',
                'border-gray-200 group-hover:border-indigo-300 dark:border-gray-700 dark:group-hover:border-indigo-600' => ! $isHighlighted,
                'border-indigo-400 ring-2 ring-indigo-400/40 dark:border-indigo-500' => $isHighlighted,
            ])>
            <x-loops.cover :loop="$item" ratio="1 / 1" rounded="rounded-none" sizes="(min-width: 768px) 25vw, (min-width: 640px) 33vw, 50vw" />
        </div>
    </a>
    <a href="{{ $loopShowHref($item) }}"
       data-loops-tile-link
       data-loop-id="{{ $item->id }}"
       data-loop-preview-url="{{ $loopPreviewHref($item) }}"
       data-loop-name="{{ $item->name }}"
       @click="openLoopPreview($event)"
       class="block rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">
        <h3 class="mt-2 line-clamp-2 text-sm font-semibold leading-snug text-gray-900 transition hover:text-indigo-600 dark:text-gray-100 dark:hover:text-indigo-400">
            {{ $item->name }}
        </h3>
    </a>

    {{-- Pastilles et crayon : FRERES du lien (jamais imbriques), poses sur la pochette. --}}
    <div class="pointer-events-none absolute inset-x-2 top-2 flex items-start justify-between gap-1">
        @if($item->is_member)
            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full border border-white/70 bg-white/95 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 shadow-sm">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                </svg>
                {{ $item->is_owner ? __('loops.role_owner') : __('loops.role_member') }}
            </span>
        @else
            <x-loops.access-badge :loop="$item" variant="overlay" />
        @endif

        @can('update', $item)
            <a href="{{ $loopEditHref($item) }}"
               class="pointer-events-auto inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-white/70 bg-white/95 text-gray-600 shadow-sm transition hover:bg-white hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
               aria-label="{{ __('loops.edit') }} — {{ $item->name }}" title="{{ __('loops.edit') }}">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/>
                </svg>
            </a>
        @endcan
    </div>

    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
        <span>{{ trans_choice('loops.presentation_members_count', $item->active_members_count, ['count' => $item->active_members_count]) }}</span>
        @if($isRecentlyActive)
            <span class="inline-flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                {{ __('loops.card_recently_active') }}
            </span>
        @endif
    </p>
</article>
