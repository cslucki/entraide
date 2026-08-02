{{-- Single Roadmap action card (mini-kanban). Clicking anywhere on the card opens
     the detail modal; interactive controls stop propagation. Drag via the handle only.
     $item, $members, $canManage, $canModify inherited from the parent view. --}}
@php
    $overdue = $item->due_at && ! $item->isDone() && $item->due_at->isPast();
    $mine = $canModify[$item->id] ?? false;
    $labelColors = [
        'violet' => 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200',
        'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-200',
        'cyan' => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-500/20 dark:text-cyan-200',
        'green' => 'bg-green-100 text-green-800 dark:bg-green-500/20 dark:text-green-200',
        'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/20 dark:text-yellow-200',
        'orange' => 'bg-orange-100 text-orange-800 dark:bg-orange-500/20 dark:text-orange-200',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200',
        'pink' => 'bg-pink-100 text-pink-800 dark:bg-pink-500/20 dark:text-pink-200',
        'gray' => 'bg-gray-200 text-gray-700 dark:bg-gray-600/40 dark:text-gray-200',
    ];
@endphp
<li wire:key="ri-{{ $item->id }}" data-roadmap-id="{{ $item->id }}"
    @if($canManage) wire:click="openDetail('{{ $item->id }}')" role="button" tabindex="0"
        wire:keydown.enter="openDetail('{{ $item->id }}')" @endif
    title="{{ __('loops.roadmap_open_detail') }}"
    class="group/card relative cursor-pointer rounded-xl border border-gray-200 bg-white p-2.5 shadow-sm transition hover:border-violet-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-900 dark:hover:border-violet-700 {{ $item->isDone() ? 'opacity-70' : '' }}">

    {{-- Labels --}}
    @if($item->labels->isNotEmpty())
        <div class="mb-1.5 flex flex-wrap gap-1">
            @foreach($item->labels->take(3) as $label)
                <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $labelColors[$label->color] ?? $labelColors['gray'] }}">{{ $label->name }}</span>
            @endforeach
            @if($item->labels->count() > 3)
                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">+{{ $item->labels->count() - 3 }}</span>
            @endif
        </div>
    @endif

    <div class="flex items-start gap-2">
        {{-- Drag handle (does not open the detail) --}}
        @if($canManage)
            <button type="button" x-on:click.stop wire:ignore
                    class="drag-handle mt-0.5 flex h-6 w-5 shrink-0 cursor-grab touch-none items-center justify-center text-gray-300 transition hover:text-gray-500 focus:text-violet-500 focus:outline-none active:cursor-grabbing dark:text-gray-600 dark:hover:text-gray-400"
                    aria-label="{{ __('loops.roadmap_drag_handle') }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
            </button>
        @endif

        {{-- Title --}}
        <p @class([
                'min-w-0 flex-1 text-sm text-gray-800 dark:text-gray-100',
                'line-through text-gray-400 dark:text-gray-500' => $item->isDone(),
            ])>
            <span class="line-clamp-3">{{ $item->title }}</span>
        </p>

        {{-- Hover: mark done (top-right). Precise target, stops propagation. --}}
        @if($canManage && ! $item->isDone())
            <button type="button" wire:click.stop="setStatus('{{ $item->id }}', 'done')"
                    title="{{ __('loops.roadmap_mark_done') }}" aria-label="{{ __('loops.roadmap_mark_done') }}"
                    class="hidden h-7 w-7 shrink-0 items-center justify-center rounded-lg text-gray-300 transition hover:bg-emerald-50 hover:text-emerald-600 group-hover/card:flex dark:hover:bg-emerald-900/30 dark:hover:text-emerald-300">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </button>
        @endif
    </div>

    {{-- Footer: due + comments (left), avatars + menu (right) --}}
    @php $footer = $item->assignees->isNotEmpty() || $item->due_at || ($item->messages_count ?? 0) > 0 || $canManage; @endphp
    @if($footer)
        <div class="mt-2 flex items-center justify-between gap-2 pl-7">
            <div class="flex items-center gap-2 text-[11px] text-gray-400 dark:text-gray-500">
                @if($item->due_at)
                    <span @class(['inline-flex items-center gap-1', 'text-red-600 dark:text-red-400 font-semibold' => $overdue])>
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75"/></svg>
                        {{ $item->due_at->isoFormat('D MMM') }}
                    </span>
                @endif
                @if(($item->messages_count ?? 0) > 0)
                    <span class="inline-flex items-center gap-1">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                        {{ $item->messages_count }}
                    </span>
                @endif
            </div>

            <div class="flex items-center gap-1.5">
                @if($item->assignees->isNotEmpty())
                    <span class="flex -space-x-1.5" title="{{ $item->assignees->map(fn ($u) => $u->publicDisplayName())->join(', ') }}">
                        @foreach($item->assignees->take(3) as $a)
                            @php $photo = $a->isDisplayableIn(currentOrganization()) ? $a->avatar_url : null; @endphp
                            @if($photo)
                                <img src="{{ $photo }}" alt="" class="h-5 w-5 rounded-full border border-white object-cover dark:border-gray-900">
                            @else
                                <span class="flex h-5 w-5 items-center justify-center rounded-full border border-white bg-violet-200 text-[9px] font-bold text-violet-800 dark:border-gray-900 dark:bg-violet-500/30 dark:text-violet-100">{{ mb_strtoupper(mb_substr($a->publicDisplayName(), 0, 1)) }}</span>
                            @endif
                        @endforeach
                    </span>
                @endif

                {{-- Direct delete (left of the menu) — no need to open the menu --}}
                @if($canManage && $mine)
                    <button type="button" x-on:click.stop="$dispatch('open-confirm', { title: @js(__('loops.roadmap_delete_title')), body: @js(__('loops.roadmap_delete_body')), confirmLabel: @js(__('loops.roadmap_delete')), danger: true, accept: 'deleteItem', params: ['{{ $item->id }}'] })"
                            title="{{ __('loops.roadmap_delete') }}" aria-label="{{ __('loops.roadmap_delete') }}"
                            class="flex h-6 w-6 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600 dark:text-gray-400 dark:hover:bg-red-900/30 dark:hover:text-red-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                    </button>
                @endif

                {{-- Actions menu (flips to stay on screen) --}}
                @if($canManage)
                    <div x-data="roadmapMenu()" class="relative" x-on:click.stop>
                        <button type="button" x-ref="btn" x-on:click="toggle()" x-bind:aria-expanded="open" aria-haspopup="true"
                                class="flex h-6 w-6 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                                aria-label="{{ __('loops.roadmap_actions_menu') }}">
                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                        </button>
                        <div x-show="open" x-cloak x-on:click.outside="close()" x-on:keydown.escape.window="close()" x-bind:style="menuStyle" x-transition.opacity
                             class="z-50 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-xl dark:border-gray-700 dark:bg-gray-800" role="menu">
                            <button type="button" wire:click="openDetail('{{ $item->id }}')" x-on:click="close()" role="menuitem"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('loops.roadmap_open_detail') }}</button>
                            <p class="px-3 pb-1 pt-1.5 text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('loops.roadmap_change_status') }}</p>
                            @foreach(array_values(array_diff(\App\Models\LoopRoadmapItem::STATUSES, [$item->status])) as $st)
                                <button type="button" wire:click="setStatus('{{ $item->id }}', '{{ $st }}')" x-on:click="close()" role="menuitem"
                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('loops.roadmap_status_'.$st) }}</button>
                            @endforeach
                            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                            <button type="button" wire:click="moveUp('{{ $item->id }}')" x-on:click="close()" role="menuitem" class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('loops.roadmap_move_up') }}</button>
                            <button type="button" wire:click="moveDown('{{ $item->id }}')" x-on:click="close()" role="menuitem" class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('loops.roadmap_move_down') }}</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</li>
