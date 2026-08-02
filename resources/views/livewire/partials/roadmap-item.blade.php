{{-- Single Roadmap action card (mini-kanban). Shared by the three columns/sections.
     Drag starts only from `.drag-handle`. $item, $editingId, $members, $orgCandidates,
     $canManage, $canModify, $canAddMembers inherited from the parent view. --}}
@php
    $overdue = $item->due_at && ! $item->isDone() && $item->due_at->isPast();
    $mine = $canModify[$item->id] ?? false;
    $otherStatuses = array_values(array_diff(\App\Models\LoopRoadmapItem::STATUSES, [$item->status]));
    $assignees = $item->assignees;
@endphp
<li wire:key="ri-{{ $item->id }}" data-roadmap-id="{{ $item->id }}"
    class="rounded-xl border border-gray-200 bg-white p-2.5 shadow-sm transition dark:border-gray-700 dark:bg-gray-900 {{ $item->isDone() ? 'opacity-70' : '' }}">
    @if($editingId === $item->id)
        {{-- Inline edit --}}
        <div class="space-y-2">
            <input wire:model="editingTitle" type="text" maxlength="255"
                   x-on:keydown.enter.prevent="$wire.saveEdit()" x-on:keydown.escape.prevent="$wire.cancelEdit()"
                   class="w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
            @include('livewire.partials.assignee-picker', ['model' => 'editingAssignees', 'options' => $members])
            <input wire:model="editingDueAt" type="date" class="w-full rounded-lg border-gray-300 bg-white text-xs text-gray-700 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200">
            <div class="flex items-center justify-end gap-2">
                <button type="button" wire:click="cancelEdit" class="text-xs font-medium text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">{{ __('loops.cancel') }}</button>
                <button type="button" wire:click="saveEdit" wire:loading.attr="disabled" wire:target="saveEdit"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:opacity-50">
                    {{ __('loops.roadmap_save') }}
                </button>
            </div>
        </div>
    @else
        <div class="flex items-start gap-2">
            {{-- Drag handle --}}
            @if($canManage)
                <button type="button"
                        class="drag-handle mt-0.5 flex h-11 w-5 shrink-0 cursor-grab touch-none items-center justify-center text-gray-300 transition hover:text-gray-500 focus:text-violet-500 focus:outline-none active:cursor-grabbing dark:text-gray-600 dark:hover:text-gray-400"
                        aria-label="{{ __('loops.roadmap_drag_handle') }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                </button>
            @endif

            <div class="min-w-0 flex-1">
                {{-- Title: tap to edit inline --}}
                <button type="button" @class([
                        'block w-full truncate text-left text-sm text-gray-800 dark:text-gray-100',
                        'line-through text-gray-400 dark:text-gray-500' => $item->isDone(),
                        'cursor-pointer hover:text-violet-700 dark:hover:text-violet-300' => $mine,
                    ])
                    @if($mine) wire:click="startEdit('{{ $item->id }}')" @else disabled @endif
                    title="{{ $item->title }}">{{ $item->title }}</button>

                {{-- Meta --}}
                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-gray-400 dark:text-gray-500">
                    @if($assignees->isNotEmpty())
                        <span class="flex -space-x-1.5" title="{{ $assignees->map(fn ($u) => $u->publicDisplayName())->join(', ') }}">
                            @foreach($assignees as $a)
                                <span class="flex h-5 w-5 items-center justify-center rounded-full border border-white bg-violet-200 text-[9px] font-bold text-violet-800 ring-0 dark:border-gray-900 dark:bg-violet-500/30 dark:text-violet-100">{{ mb_strtoupper(mb_substr($a->publicDisplayName(), 0, 1)) }}</span>
                            @endforeach
                        </span>
                    @endif
                    @if($item->due_at)
                        <span @class([
                            'inline-flex items-center gap-1',
                            'text-red-600 dark:text-red-400 font-semibold' => $overdue,
                        ])>
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            {{ $item->due_at->isoFormat('D MMM') }}@if($overdue) · {{ __('loops.roadmap_overdue') }}@endif
                        </span>
                    @endif
                </div>
            </div>

            {{-- Actions menu --}}
            @if($canManage)
                <div x-data="{ open: false, sub: null, q: '' }" class="relative shrink-0">
                    <button type="button" x-on:click="open = !open; sub = null" x-bind:aria-expanded="open" aria-haspopup="true"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-300 transition hover:bg-gray-100 hover:text-gray-600 dark:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                            aria-label="{{ __('loops.roadmap_actions_menu') }}">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false; sub = null" x-transition.opacity
                         class="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800" role="menu">

                        {{-- Change status --}}
                        <p class="px-3 pb-1 pt-1.5 text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('loops.roadmap_change_status') }}</p>
                        @foreach($otherStatuses as $st)
                            <button type="button" wire:click="setStatus('{{ $item->id }}', '{{ $st }}')" x-on:click="open = false" role="menuitem"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                                {{ __('loops.roadmap_status_'.$st) }}
                            </button>
                        @endforeach

                        {{-- Add an Organization member (category B) then assign --}}
                        @if($mine && $canAddMembers && $orgCandidates->isNotEmpty())
                            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                            <button type="button" x-on:click="sub = (sub === 'org' ? null : 'org'); q = ''" role="menuitem"
                                    class="flex w-full items-center justify-between px-3 py-1.5 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                                {{ __('loops.roadmap_group_org_members') }}
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                            </button>
                            <div x-show="sub === 'org'" x-cloak class="bg-gray-50 dark:bg-gray-900/40">
                                <input type="text" x-model="q" placeholder="{{ __('loops.roadmap_search_member') }}"
                                       class="m-2 w-[calc(100%-1rem)] rounded-lg border-gray-300 bg-white text-xs dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                                <div class="max-h-40 overflow-y-auto pb-1">
                                    @foreach($orgCandidates as $c)
                                        <button type="button"
                                                x-show="q === '' || @js(mb_strtolower($c['name'])).includes(q.toLowerCase())"
                                                x-on:click="open = false; sub = null; $dispatch('open-confirm', { title: @js(__('loops.roadmap_add_member_title')), body: @js(__('loops.roadmap_add_member_body')), confirmLabel: @js(__('loops.roadmap_add_and_assign')), accept: 'assignAndAddMember', params: ['{{ $item->id }}', '{{ $c['id'] }}'] })"
                                                class="block w-full truncate px-5 py-1.5 text-left text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ $c['name'] }} <span class="text-[10px] text-gray-400">+</span></button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Reorder fallback + edit/delete --}}
                        <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                        <button type="button" wire:click="moveUp('{{ $item->id }}')" x-on:click="open = false" role="menuitem"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5"/></svg>{{ __('loops.roadmap_move_up') }}
                        </button>
                        <button type="button" wire:click="moveDown('{{ $item->id }}')" x-on:click="open = false" role="menuitem"
                                class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>{{ __('loops.roadmap_move_down') }}
                        </button>
                        @if($mine)
                            <button type="button" wire:click="startEdit('{{ $item->id }}')" x-on:click="open = false" role="menuitem"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>{{ __('loops.roadmap_edit') }}
                            </button>
                            <button type="button" role="menuitem"
                                    x-on:click="open = false; $dispatch('open-confirm', { title: @js(__('loops.roadmap_delete_title')), body: @js(__('loops.roadmap_delete_body')), confirmLabel: @js(__('loops.roadmap_delete')), danger: true, accept: 'deleteItem', params: ['{{ $item->id }}'] })"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165"/></svg>{{ __('loops.roadmap_delete') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
</li>
