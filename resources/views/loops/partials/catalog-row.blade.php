{{--
    TASK-1864 — une Boucle du catalogue en mode LISTE (`/loops?view=list`).

    Meme donnees et memes regles que `catalog-card` : meme condition de filtre
    (`CatalogFilter::xShow`), meme action principale (`catalog-cta`), meme
    pastille de role ou badge d'acces. Ce qui change est la place donnee a
    l'activite :

    - MEMBRE : le dernier message humain (auteur + extrait + date), charge par
      `LoopController::index()` dans `recentHumanMessages` (1 par Boucle) ;
    - NON-MEMBRE : jamais un mot de la conversation. Sa ligne garde ce que la
      carte montrait deja — accroche, nombre de membres, « Actif recemment ».

    Le nom porte un lien etire (`after:inset-0`) : toute la ligne mene a la
    Boucle, les actions (crayon, CTA) passent au-dessus avec `relative z-10`.

    TASK-1867 — liste G : ce lien reste le VRAI lien de la Boucle (sans JS, clic
    modifie). Avec JS, le clic normal choisit la Boucle dans le volet de detail
    (`loopsListPane`, list-pane), les fleches haut/bas passent d'une ligne
    visible a l'autre. Aucune regle d'affichage ne change.
--}}
@php
    $cardTagline = filled($item->tagline) ? $item->tagline : (filled($item->description) ? $item->description : null);
    $lastActivityAt = $item->last_message_at ? \Carbon\Carbon::parse($item->last_message_at) : null;
    $isRecentlyActive = $lastActivityAt && $lastActivityAt->gt(now()->subDays(7));
    $isHighlighted = ($highlightedLoopId ?? null) === $item->id;
    $itemType = app(\App\Support\Loops\LoopTypeRegistry::class)->resolve($item->type);

    // Charge UNIQUEMENT pour les membres (voir le controleur) : sur une ligne
    // de non-membre la relation n'est pas chargee, et on ne la charge pas.
    $lastMessage = $item->is_member && $item->relationLoaded('recentHumanMessages')
        ? $item->recentHumanMessages->first()
        : null;
    $lastMessageBody = $lastMessage ? trim(preg_replace('/\s+/u', ' ', (string) $lastMessage->body)) : '';
@endphp
<div
    data-type="{{ $itemType }}"
    data-loops-catalog-row="{{ $item->id }}"
    x-show="{{ \App\Support\Loops\CatalogFilter::xShow($item) }}"
    @class([
        'group relative flex items-center gap-4 rounded-xl px-3 py-3 transition hover:bg-gray-50 lg:flex-wrap dark:hover:bg-gray-700/40',
        'ring-2 ring-indigo-400/50' => $isHighlighted,
    ])
    :class="loopPane.id === @js($item->id) && 'bg-indigo-50 dark:bg-indigo-900/30'"
>
    <div class="w-16 shrink-0 sm:w-20">
        <x-loops.cover :loop="$item" ratio="4 / 3" rounded="rounded-lg" />
    </div>

    <div class="min-w-0 flex-1">
        {{-- TASK-1867 — `flex-wrap` : dans la colonne etroite de la liste G, le
             badge passe sous le nom au lieu de le reduire a rien. --}}
        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
            <h3 class="min-w-0 max-w-full truncate font-semibold text-gray-900 dark:text-gray-100">
                <a href="{{ $loopShowHref($item) }}"
                   data-loops-row-link
                   data-loop-id="{{ $item->id }}"
                   data-loop-preview-url="{{ $loopPreviewHref($item) }}"
                   data-loop-name="{{ $item->name }}"
                   @click="onLoopRowClick($event)"
                   @keydown.arrow-down="onLoopRowArrow($event, 1)"
                   @keydown.arrow-up="onLoopRowArrow($event, -1)"
                   :aria-current="loopPane.id === @js($item->id) ? 'true' : null"
                   class="after:absolute after:inset-0 after:rounded-xl focus:outline-none focus-visible:underline group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                    {{ $item->name }}
                </a>
            </h3>
            @if($item->is_member)
                <span class="shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                    {{ $item->is_owner ? __('loops.role_owner') : __('loops.role_member') }}
                </span>
            @else
                <span class="hidden shrink-0 sm:inline-flex"><x-loops.access-badge :loop="$item" /></span>
            @endif
        </div>

        @if($item->is_member)
            @if($lastMessage)
                {{-- Deux lignes sur smartphone : l'auteur seul y remplirait la
                     premiere, et l'extrait — le but de la ligne — disparaitrait. --}}
                <p class="mt-0.5 line-clamp-2 break-words text-sm text-gray-600 sm:line-clamp-1 dark:text-gray-300" data-loops-catalog-row-last-message>
                    @if($lastMessage->sender)
                        <span class="font-medium text-gray-800 dark:text-gray-100">{{ $lastMessage->sender->publicDisplayName() }}</span>
                        <span aria-hidden="true">:</span>
                    @endif
                    @if($lastMessageBody !== '')
                        {{ \Illuminate\Support\Str::limit($lastMessageBody, 140) }}
                    @elseif($lastMessage->imageUrl())
                        <span class="italic text-gray-400 dark:text-gray-500">{{ __('loops.latest_messages_image_only') }}</span>
                    @endif
                </p>
            @else
                <p class="mt-0.5 text-sm italic text-gray-400 dark:text-gray-500">{{ __('loops.catalog_row_no_message') }}</p>
            @endif
        @elseif($cardTagline)
            <p class="mt-0.5 truncate text-sm text-gray-600 dark:text-gray-300">{{ $cardTagline }}</p>
        @else
            <p class="mt-0.5 text-sm italic text-gray-400 dark:text-gray-500">{{ __('loops.card_no_pitch') }}</p>
        @endif

        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-400 dark:text-gray-500">
            <span>{{ trans_choice('loops.presentation_members_count', $item->active_members_count, ['count' => $item->active_members_count]) }}</span>
            @if($lastMessage)
                <span aria-hidden="true">·</span>
                <time datetime="{{ $lastMessage->created_at->toIso8601String() }}">{{ $lastMessage->created_at->diffForHumans() }}</time>
            @elseif(! $item->is_member && $isRecentlyActive)
                <span aria-hidden="true">·</span>
                <span class="inline-flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    {{ __('loops.card_recently_active') }}
                </span>
            @endif
        </div>
    </div>

    {{-- TASK-1867 — a partir de 1024 px (colonne etroite de la liste G),
         l'action d'adhesion d'un non-membre prend sa propre ligne. --}}
    <div @class(['flex shrink-0 items-center gap-2', 'lg:basis-full lg:justify-end' => ! $item->is_member])>
        @can('update', $item)
            <a href="{{ $loopEditHref($item) }}"
               class="relative z-10 inline-flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-gray-700 dark:hover:text-indigo-300"
               aria-label="{{ __('loops.edit') }} — {{ $item->name }}" title="{{ __('loops.edit') }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/>
                </svg>
            </a>
        @endcan
        @unless($item->is_member)
            @include('loops.partials.catalog-cta', ['item' => $item, 'ctaWidth' => ''])
        @endunless
    </div>
</div>
