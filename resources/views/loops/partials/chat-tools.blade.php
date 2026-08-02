{{-- ChatLoop card internal header: "Lancer" + tool buttons + expand chat.
     Rendered INSIDE the left chat card (not as a global toolbar). Relies on the
     root Alpine scope (activeCard / openCard / toggleChatFocus / todos) and the
     $workspaceCards / $cardAccents / $manifestoVersion variables from show.blade. --}}
<div class="flex items-center gap-3 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
    <span class="hidden shrink-0 items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-[0.04em] text-gray-400 dark:text-gray-500 sm:inline-flex" title="{{ __('loops.cards_bar_hint') }}">
        <svg class="h-4 w-4 text-violet-500 dark:text-violet-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 4V2m0 20v-2M9 4l1 1m4 14 1 1M4 9h2m14 6h2M4 15l1-1m14-4 1-1M6.5 17.5 17 7"/></svg>
        {{ __('loops.cards_bar_launch') }}
    </span>

    <div class="flex min-w-0 flex-1 items-center gap-2">
        @foreach($workspaceCards as $card)
            <button
                type="button"
                x-on:click="openCard(@js($card['key']))"
                x-bind:aria-pressed="activeCard === @js($card['key'])"
                x-bind:class="activeCard === @js($card['key']) ? 'border-violet-300 bg-white ring-1 ring-violet-200 shadow-sm dark:border-violet-600/70 dark:bg-gray-800 dark:ring-violet-700/50' : 'border-gray-200 bg-white hover:border-violet-200 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:hover:border-violet-700'"
                class="group inline-flex min-h-11 shrink-0 items-center gap-2.5 rounded-2xl border px-3 py-1.5 transition"
            >
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl {{ $cardAccents[$card['key']] ?? 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300' }}" aria-hidden="true">
                    @switch($card['icon'] ?? '')
                        @case('sparkles')
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/></svg>
                            @break
                        @case('document')
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 3v4.5h4.5"/></svg>
                            @break
                        @case('map')
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m0 0 3-3m-3 3-3-3M15 9v8.25M15 17.25l3-3m-3 3-3-3"/></svg>
                            @break
                        @case('users')
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
                            @break
                        @default
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m0 0 3-3m-3 3-3-3M15 9v8.25M15 17.25l3-3m-3 3-3-3"/></svg>
                    @endswitch
                </span>
                <span class="text-sm font-bold tracking-tight text-gray-800 dark:text-gray-100">{{ __($card['label_key']) }}</span>
                @if($card['key'] === 'core.members')
                    <span class="ml-0.5 rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ $loopMembers->count() }}</span>
                    @if(($canManageJoinRequests ?? false) && $pendingJoinRequests->isNotEmpty())
                        <span class="ml-0.5 rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white" title="{{ __('loops.join_requests_title') }}">{{ $pendingJoinRequests->count() }}</span>
                    @endif
                @endif
            </button>
        @endforeach
    </div>

    {{-- Expand chat (focus) — desktop only, when a panel is open --}}
    <button
        type="button"
        x-show="activeCard"
        x-cloak
        x-on:click="toggleChatFocus()"
        x-bind:aria-pressed="focus === 'chat'"
        class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:border-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200 lg:inline-flex"
        aria-label="{{ __('loops.cards_bar_expand_chat') }}"
        title="{{ __('loops.cards_bar_expand_chat') }}"
    >
        <svg x-show="focus !== 'chat'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5-5-5m5 5v-4m0 4h-4"/></svg>
        <svg x-show="focus === 'chat'" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V5m0 4H5m4 0L4 4m11 5h4m-4 0V5m0 4 5-5M9 15v4m0-4H5m4 0-5 5m11-5h4m-4 0v4m0-4 5 5"/></svg>
    </button>
</div>
