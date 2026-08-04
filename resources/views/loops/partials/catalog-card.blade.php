{{--
    Catalog card, shared by both the "Mes Boucles" and "Découvrir" sections.
    Expects $item (Loop, annotated by LoopController::getAccessibleLoopsQuery)
    plus the $loopShowHref / $loopJoinAction / $loopEditHref closures and the
    $highlightedLoopId from loops/index.blade.php.
--}}
@php
    // Multi-owner (CP5ter): several owners have equal standing, so the card
    // names them rather than picking one as if it were the owner.
    $ownerUser = $item->owner?->user;
    $searchable = mb_strtolower($item->name.' '.($item->tagline ?? '').' '.($item->description ?? ''));

    // Never print the same sentence twice when tagline and description match.
    $cardTagline = filled($item->tagline) ? $item->tagline : null;
    $cardDescription = filled($item->description) ? $item->description : null;
    if ($cardTagline && $cardDescription && mb_strtolower(trim($cardTagline)) === mb_strtolower(trim($cardDescription))) {
        $cardDescription = null;
    }
    if (! $cardTagline && $cardDescription) {
        $cardTagline = $cardDescription;
        $cardDescription = null;
    }

    $lastActivityAt = $item->last_message_at ? \Carbon\Carbon::parse($item->last_message_at) : null;
    $isRecentlyActive = $lastActivityAt && $lastActivityAt->gt(now()->subDays(7));

    $isHighlighted = ($highlightedLoopId ?? null) === $item->id;
    $domainIds = $item->categories->pluck('id')->all();
@endphp
<article
    x-show="(q === '' || {{ \Illuminate\Support\Js::from($searchable) }}.includes(q.toLowerCase()))
            && (domain === '' || {{ \Illuminate\Support\Js::from($domainIds) }}.includes(domain))"
    @class([
        'group flex flex-col overflow-hidden rounded-2xl border bg-white transition hover:-translate-y-0.5 hover:shadow-lg dark:bg-gray-800',
        'border-gray-200 hover:border-indigo-300 dark:border-gray-700 dark:hover:border-indigo-600' => ! $isHighlighted,
        'border-indigo-400 ring-2 ring-indigo-400/40 dark:border-indigo-500' => $isHighlighted,
    ])
>
    {{-- Cover + overlays. The edit action is a SIBLING of the cover link
         (never nested inside it) so the markup stays valid. --}}
    <div class="relative">
        <a href="{{ $loopShowHref($item) }}" class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
           tabindex="-1" aria-hidden="true">
            <x-loops.cover :loop="$item" />
        </a>

        @if($item->is_member)
            <span class="absolute left-3 top-3 inline-flex items-center gap-1 whitespace-nowrap rounded-full border border-white/70 bg-white/95 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 shadow-sm backdrop-blur-sm">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                </svg>
                {{ $item->is_owner ? __('loops.role_owner') : __('loops.role_member') }}
            </span>
        @endif

        <div class="absolute right-3 top-3 flex items-center gap-2">
            <x-loops.access-badge :loop="$item" variant="overlay" />

            @can('update', $item)
                <a href="{{ $loopEditHref($item) }}"
                   class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-white/70 bg-white/95 text-gray-600 shadow-sm backdrop-blur-sm transition hover:bg-white hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                   aria-label="{{ __('loops.edit') }} — {{ $item->name }}" title="{{ __('loops.edit') }}">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/>
                    </svg>
                </a>
            @endcan
        </div>
    </div>

    <div class="flex flex-1 flex-col p-4">
        <h3 class="mb-1 font-bold leading-snug text-gray-900 dark:text-gray-100">
            <a href="{{ $loopShowHref($item) }}"
               class="line-clamp-1 transition group-hover:text-indigo-600 focus:outline-none focus-visible:underline dark:group-hover:text-indigo-400">
                {{ $item->name }}
            </a>
        </h3>

        @if($cardTagline)
            <p class="line-clamp-2 text-sm font-medium leading-snug text-gray-600 dark:text-gray-300">{{ $cardTagline }}</p>
        @else
            <p class="text-sm italic leading-snug text-gray-400 dark:text-gray-500">{{ __('loops.card_no_pitch') }}</p>
        @endif

        @if($cardDescription)
            <p class="mt-1 line-clamp-2 text-sm leading-snug text-gray-500 dark:text-gray-400">{{ $cardDescription }}</p>
        @endif

        <x-loops.domain-badges :loop="$item" class="mt-2" />

        <div class="mt-auto pt-3">
            <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400 dark:text-gray-500">
                @if($ownerUser)
                    <span class="inline-flex min-w-0 items-center gap-1.5">
                        @if($ownerUser->avatar_url)
                            <img src="{{ $ownerUser->avatar_url }}" alt="" class="h-4 w-4 shrink-0 rounded-full object-cover">
                        @endif
                        <span class="truncate"><x-loops.owner-names :owners="$item->owners" /></span>
                    </span>
                    <span aria-hidden="true">·</span>
                @endif

                <span class="inline-flex shrink-0 items-center gap-1">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    {{ trans_choice('loops.presentation_members_count', $item->active_members_count, ['count' => $item->active_members_count]) }}
                </span>

                @if($isRecentlyActive)
                    <span aria-hidden="true">·</span>
                    <span class="inline-flex shrink-0 items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        {{ __('loops.card_recently_active') }}
                    </span>
                @endif
            </div>

            @if($item->is_member)
                <a href="{{ $loopShowHref($item) }}"
                   class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                    {{ __('loops.cta_open_workspace') }}
                </a>
            @elseif($item->isOpenAccess())
                <form method="POST" action="{{ $loopJoinAction($item) }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                        {{ __('loops.cta_join') }}
                    </button>
                </form>
            @elseif($item->isRequestAccess())
                @if($item->has_pending_request)
                    <span class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                        {{ __('loops.cta_pending') }}
                    </span>
                @else
                    <a href="{{ $loopShowHref($item) }}"
                       class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-indigo-200 px-3 py-2 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/20">
                        {{ __('loops.cta_request') }}
                    </a>
                @endif
            @else
                <a href="{{ $loopShowHref($item) }}"
                   class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-500 transition hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-700">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                    </svg>
                    {{ __('loops.cta_discover') }}
                </a>
            @endif
        </div>
    </div>
</article>
