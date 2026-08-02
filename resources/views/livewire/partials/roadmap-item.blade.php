{{-- Single Roadmap action row. Shared by the "To do" and "Done" groups.
     Drag starts only from `.drag-handle` (Sortable handle); other controls never drag.
     $item, $editingId, $members, $canManage, $canModify are inherited from the parent view. --}}
<li wire:key="ri-{{ $item->id }}" data-roadmap-id="{{ $item->id }}"
    class="rounded-xl border border-gray-200 bg-white p-3 transition dark:border-gray-700 dark:bg-gray-900 {{ $item->isDone() ? 'opacity-60' : '' }}">
    @if($editingId === $item->id)
        {{-- Inline edit --}}
        <div class="space-y-2">
            <input wire:model="editingTitle" type="text" maxlength="255"
                   class="w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model="editingAssignee" class="min-w-0 flex-1 rounded-lg border-gray-300 bg-white text-xs text-gray-700 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200">
                    <option value="">{{ __('loops.roadmap_assign_none') }}</option>
                    @foreach($members as $m)
                        <option value="{{ $m['id'] }}">{{ $m['name'] }}</option>
                    @endforeach
                </select>
                <input wire:model="editingDueAt" type="date" class="rounded-lg border-gray-300 bg-white text-xs text-gray-700 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200">
            </div>
            <div class="flex items-center justify-end gap-2">
                <button type="button" wire:click="cancelEdit" class="text-xs font-medium text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">{{ __('loops.cancel') }}</button>
                <button type="button" wire:click="saveEdit" wire:loading.attr="disabled" wire:target="saveEdit"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:opacity-50">
                    {{ __('loops.roadmap_save') }}
                </button>
            </div>
        </div>
    @else
        <div class="flex items-center gap-2.5">
            {{-- Drag handle (active members) — the only element that starts a drag --}}
            @if($canManage)
                <button type="button"
                        class="drag-handle flex h-11 w-6 shrink-0 cursor-grab touch-none items-center justify-center text-gray-300 transition hover:text-gray-500 focus:text-violet-500 focus:outline-none active:cursor-grabbing dark:text-gray-600 dark:hover:text-gray-400"
                        aria-label="{{ __('loops.roadmap_drag_handle') }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                </button>
            @endif

            {{-- Toggle done --}}
            <button type="button" wire:click="toggle('{{ $item->id }}')" wire:loading.attr="disabled" wire:target="toggle('{{ $item->id }}')"
                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md border transition {{ $item->isDone() ? 'border-violet-500 bg-violet-500 text-white' : 'border-gray-300 text-transparent hover:border-violet-400 dark:border-gray-600' }}"
                    aria-pressed="{{ $item->isDone() ? 'true' : 'false' }}"
                    aria-label="{{ $item->isDone() ? __('loops.roadmap_reopen') : __('loops.roadmap_complete') }}">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            </button>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-gray-800 dark:text-gray-100 {{ $item->isDone() ? 'line-through text-gray-400 dark:text-gray-500' : '' }}">{{ $item->title }}</p>
                @if($item->assignee || $item->due_at)
                    <p class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-gray-400 dark:text-gray-500">
                        @if($item->assignee)
                            <span class="inline-flex items-center gap-1"><svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>{{ $item->assignee->publicDisplayName() }}</span>
                        @endif
                        @if($item->due_at)
                            <span class="inline-flex items-center gap-1"><svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>{{ $item->due_at->isoFormat('D MMM') }}</span>
                        @endif
                    </p>
                @endif
            </div>

            {{-- Discreet actions menu: keyboard/a11y reorder fallback + edit/delete --}}
            @if($canManage)
                <div x-data="{ open: false }" class="relative shrink-0">
                    <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open" aria-haspopup="true"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-300 transition hover:bg-gray-100 hover:text-gray-600 dark:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                            aria-label="{{ __('loops.roadmap_actions_menu') }}">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
                         class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                         role="menu">
                        <button type="button" wire:click="moveUp('{{ $item->id }}')" x-on:click="open = false" role="menuitem"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5"/></svg>
                            {{ __('loops.roadmap_move_up') }}
                        </button>
                        <button type="button" wire:click="moveDown('{{ $item->id }}')" x-on:click="open = false" role="menuitem"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                            {{ __('loops.roadmap_move_down') }}
                        </button>
                        @if($canModify[$item->id] ?? false)
                            <div class="my-1 border-t border-gray-100 dark:border-gray-700"></div>
                            <button type="button" wire:click="startEdit('{{ $item->id }}')" x-on:click="open = false" role="menuitem"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                {{ __('loops.roadmap_edit') }}
                            </button>
                            <button type="button" wire:click="deleteItem('{{ $item->id }}')" wire:confirm="{{ __('loops.roadmap_delete_confirm') }}" x-on:click="open = false" role="menuitem"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                {{ __('loops.roadmap_delete') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
</li>
