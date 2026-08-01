<div class="space-y-4">
    {{-- Header --}}
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('loops.cards.roadmap.label') }}</p>
        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('loops.cards.roadmap.description') }}</p>
    </div>

    @if($errorMessage)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">
            {{ $errorMessage }}
        </div>
    @endif

    @if($canManage)
        <form wire:submit="add" class="flex items-center gap-2">
            <input wire:model="newTitle" type="text" maxlength="255" placeholder="{{ __('loops.roadmap_add_placeholder') }}"
                   class="min-w-0 flex-1 rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <button type="submit" wire:loading.attr="disabled" wire:target="add"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-600 text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50"
                    aria-label="{{ __('loops.cards.roadmap.action') }}">
                <svg wire:loading.remove wire:target="add" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                <svg wire:loading wire:target="add" class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            </button>
        </form>
    @endif

    @if($items->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-900">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-200">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </div>
            <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.roadmap.empty_title') }}</h3>
            <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_empty_pitch') }}</p>
        </div>
    @else
        <ul class="space-y-2">
            @foreach($items as $item)
                <li wire:key="ri-{{ $item->id }}"
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
                        <div class="flex items-center gap-3">
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

                            {{-- Reorder (active members) --}}
                            <div class="flex shrink-0 flex-col">
                                <button type="button" wire:click="moveUp('{{ $item->id }}')" class="text-gray-300 transition hover:text-gray-600 dark:hover:text-gray-300" aria-label="{{ __('loops.roadmap_move_up') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5"/></svg>
                                </button>
                                <button type="button" wire:click="moveDown('{{ $item->id }}')" class="text-gray-300 transition hover:text-gray-600 dark:hover:text-gray-300" aria-label="{{ __('loops.roadmap_move_down') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                                </button>
                            </div>

                            {{-- Edit / delete (creator, moderator, owner, super-admin) --}}
                            @if($canModify[$item->id] ?? false)
                                <button type="button" wire:click="startEdit('{{ $item->id }}')" class="shrink-0 text-gray-300 transition hover:text-violet-600 dark:hover:text-violet-300" aria-label="{{ __('loops.roadmap_edit') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                </button>
                                <button type="button" wire:click="deleteItem('{{ $item->id }}')" wire:confirm="{{ __('loops.roadmap_delete_confirm') }}" class="shrink-0 text-gray-300 transition hover:text-red-500 dark:hover:text-red-400" aria-label="{{ __('loops.roadmap_delete') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                </button>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <p class="text-center text-[11px] text-gray-400 dark:text-gray-500">
            {{ trans_choice('loops.roadmap_open_count', $openCount, ['count' => $openCount]) }}
        </p>
    @endif
</div>
