@php $statusMeta = [
    \App\Models\LoopRoadmapItem::STATUS_TODO => ['label' => 'roadmap_status_todo', 'dot' => 'bg-gray-400'],
    \App\Models\LoopRoadmapItem::STATUS_IN_PROGRESS => ['label' => 'roadmap_status_in_progress', 'dot' => 'bg-amber-400'],
    \App\Models\LoopRoadmapItem::STATUS_DONE => ['label' => 'roadmap_status_done', 'dot' => 'bg-emerald-400'],
]; @endphp
<div class="space-y-4"
     data-roadmap-root
     data-roadmap-can-manage="{{ $canManage ? '1' : '0' }}"
     x-data="{
        createOpen: false,
        openCreate(status) { $wire.set('newStatus', status); this.createOpen = true; this.$nextTick(() => this.$refs.createTitle && this.$refs.createTitle.focus()); },
     }"
     x-on:roadmap-action-created.window="createOpen = false">
    @once
        <style>
            .roadmap-ghost { opacity: .45; }
            .roadmap-chosen { box-shadow: 0 8px 20px -6px rgba(124,58,237,.35); }
            [data-roadmap-group] .drag-handle { touch-action: none; }
            .roadmap-board { display: flex; gap: .625rem; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
            .roadmap-col { flex: 0 0 84vw; scroll-snap-align: start; min-width: 0; }
            @media (min-width: 1024px) {
                .roadmap-board { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); overflow: visible; }
                .roadmap-col { flex: none; }
            }
        </style>
    @endonce

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
        {{-- Global add button --}}
        <button type="button" x-on:click="openCreate(@js(\App\Models\LoopRoadmapItem::STATUS_TODO))"
                class="inline-flex items-center gap-2 rounded-xl bg-violet-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-violet-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            {{ __('loops.roadmap_add_action') }}
        </button>

        @if($items->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-900">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
                <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.roadmap.empty_title') }}</h3>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_empty_pitch') }}</p>
            </div>
        @else
            {{-- Mini-kanban board: 3 columns (desktop simultaneous, mobile horizontal scroll-snap) --}}
            <div class="roadmap-board pb-1">
                @foreach($columns as $status => $colItems)
                    <section class="roadmap-col flex flex-col rounded-2xl border border-gray-200 bg-gray-50/60 p-2 dark:border-gray-700 dark:bg-gray-800/40">
                        <div class="mb-2 flex items-center justify-between px-1">
                            <p class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <span class="h-2 w-2 rounded-full {{ $statusMeta[$status]['dot'] }}"></span>
                                {{ __('loops.'.$statusMeta[$status]['label']) }}
                                <span class="rounded-full bg-gray-200 px-1.5 text-[10px] text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $colItems->count() }}</span>
                            </p>
                            <button type="button" x-on:click="openCreate(@js($status))"
                                    class="flex h-6 w-6 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                    aria-label="{{ __('loops.roadmap_add_action') }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            </button>
                        </div>
                        <ul class="min-h-[3rem] flex-1 space-y-2" data-roadmap-group data-status="{{ $status }}">
                            @foreach($colItems as $item)
                                @include('livewire.partials.roadmap-item')
                            @endforeach
                        </ul>
                        @if($colItems->isEmpty())
                            <p class="px-2 py-3 text-center text-[11px] text-gray-400 dark:text-gray-600">{{ __('loops.roadmap_empty_column') }}</p>
                        @endif
                    </section>
                @endforeach
            </div>

            <p class="text-center text-[11px] text-gray-400 dark:text-gray-500">
                {{ trans_choice('loops.roadmap_open_count', $openCount, ['count' => $openCount]) }}
            </p>
        @endif

        {{-- Create action modal (premium "+") --}}
        <template x-if="createOpen">
            <div class="fixed inset-0 z-[65] flex items-end justify-center p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="{{ __('loops.roadmap_create_title') }}">
                <div class="absolute inset-0 bg-black/50" x-on:click="createOpen = false"></div>
                <form wire:submit="createAction"
                      class="relative w-full rounded-t-2xl bg-white p-5 shadow-xl dark:bg-gray-900 sm:max-w-md sm:rounded-2xl"
                      x-on:keydown.escape.window="createOpen = false">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.roadmap_create_title') }}</h3>
                    <div class="mt-4 space-y-3">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_field_title') }}</label>
                            <input x-ref="createTitle" wire:model="newTitle" type="text" maxlength="255" placeholder="{{ __('loops.roadmap_field_title_placeholder') }}"
                                   class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_field_status') }}</label>
                                <select wire:model="newStatus" class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-700 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200">
                                    @foreach($columns as $status => $colItems)
                                        <option value="{{ $status }}">{{ __('loops.'.$statusMeta[$status]['label']) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_field_due') }}</label>
                                <input wire:model="newDueAt" type="date" class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-700 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200">
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_field_assignee') }}</label>
                            <select wire:model="newAssignee" class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-700 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200">
                                <option value="">{{ __('loops.roadmap_assign_none') }}</option>
                                @foreach($members as $m)
                                    <option value="{{ $m['id'] }}">{{ $m['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($errorMessage)
                            <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">{{ $errorMessage }}</p>
                        @endif
                    </div>
                    <div class="mt-5 flex items-center justify-end gap-2">
                        <button type="button" x-on:click="createOpen = false" class="rounded-xl px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">{{ __('loops.cancel') }}</button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="createAction"
                                class="inline-flex items-center gap-2 rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-700 disabled:opacity-50">
                            {{ __('loops.roadmap_create_submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </template>
    @endif

    {{-- Reusable premium confirmation modal (delete, add-and-assign) --}}
    <x-confirm-dialog />
</div>
