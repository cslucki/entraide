@php
    $searchable = mb_strtolower($item->name.' '.($item->tagline ?? ''));
    $ownerUser = $item->owner?->user;
@endphp
<div
    x-show="q === '' || {{ \Illuminate\Support\Js::from($searchable) }}.includes(q.toLowerCase())"
    class="group flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white transition hover:shadow-lg hover:border-indigo-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-indigo-600"
>
    <a href="{{ $loopShowHref($item) }}" class="block">
        <x-loops.cover :loop="$item" />
    </a>

    <div class="flex flex-1 flex-col p-4">
        <div class="mb-1 flex items-start justify-between gap-2">
            <a href="{{ $loopShowHref($item) }}" class="min-w-0">
                <h3 class="truncate font-bold text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">{{ $item->name }}</h3>
            </a>
            <x-loops.access-badge :loop="$item" />
        </div>

        @if($item->tagline)
            <p class="mb-1 text-sm font-medium text-gray-600 dark:text-gray-300">{{ $item->tagline }}</p>
        @elseif($item->description)
            <p class="mb-1 text-sm text-gray-500 dark:text-gray-400 line-clamp-2">{{ $item->description }}</p>
        @endif

        <div class="mt-auto flex items-center gap-3 pt-3 text-xs text-gray-400 dark:text-gray-500">
            <span class="flex items-center gap-1">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                {{ $item->active_members_count }}
            </span>
            @if($ownerUser)
                <span class="truncate">{{ __('loops.presentation_animator') }} {{ $ownerUser->publicDisplayName() }}</span>
            @endif
        </div>

        <div class="mt-3">
            @if($item->is_member)
                <a href="{{ $loopShowHref($item) }}"
                   class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                    {{ __('loops.cta_open_workspace') }}
                </a>
            @elseif($item->isOpenAccess())
                <form method="POST" action="{{ $loopJoinAction($item) }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
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
                       class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-indigo-200 px-3 py-2 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-900/20">
                        {{ __('loops.cta_request') }}
                    </a>
                @endif
            @else
                <span class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-400 dark:bg-gray-900 dark:text-gray-500">
                    {{ __('loops.cta_invitation') }}
                </span>
            @endif
        </div>
    </div>
</div>
