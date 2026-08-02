@php $statusMeta = [
    \App\Models\LoopRoadmapItem::STATUS_TODO => [
        'label' => 'roadmap_status_todo', 'dot' => 'bg-violet-500',
        'col' => 'border-violet-200/70 bg-violet-50/60 dark:border-violet-900/40 dark:bg-violet-950/25',
        'chip' => 'text-violet-700 dark:text-violet-300',
    ],
    \App\Models\LoopRoadmapItem::STATUS_IN_PROGRESS => [
        'label' => 'roadmap_status_in_progress', 'dot' => 'bg-amber-500',
        'col' => 'border-amber-200/70 bg-amber-50/60 dark:border-amber-900/40 dark:bg-amber-950/25',
        'chip' => 'text-amber-700 dark:text-amber-300',
    ],
    \App\Models\LoopRoadmapItem::STATUS_DONE => [
        'label' => 'roadmap_status_done', 'dot' => 'bg-emerald-500',
        'col' => 'border-emerald-200/70 bg-emerald-50/60 dark:border-emerald-900/40 dark:bg-emerald-950/25',
        'chip' => 'text-emerald-700 dark:text-emerald-300',
    ],
]; @endphp
<div class="space-y-3"
     data-roadmap-root
     data-roadmap-can-manage="{{ $canManage ? '1' : '0' }}"
     x-data="{
        createOpen: false,
        archivesOpen: false,
        mode: (localStorage.getItem('roadmapView') || 'kanban'),
        openCreate(status) { $wire.set('newStatus', status); this.createOpen = true; this.$nextTick(() => this.$refs.createTitle && this.$refs.createTitle.focus()); },
     }"
     x-init="$watch('mode', v => localStorage.setItem('roadmapView', v))"
     x-on:roadmap-action-created.window="createOpen = false">
    @once
        <style>
            .roadmap-ghost { opacity: .45; }
            .roadmap-chosen { box-shadow: 0 8px 20px -6px rgba(124,58,237,.35); }
            [data-roadmap-group] .drag-handle { touch-action: none; }
            /* Kanban: horizontal columns (mobile scroll-snap, desktop grid). */
            .roadmap-board { display: flex; gap: .625rem; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
            .roadmap-board .roadmap-col { flex: 0 0 84vw; scroll-snap-align: start; min-width: 0; }
            @media (min-width: 1024px) {
                .roadmap-board { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); overflow: visible; }
                .roadmap-board .roadmap-col { flex: none; }
            }
            /* List: full-width stacked status sections. */
            .roadmap-list { display: flex; flex-direction: column; gap: .75rem; }
            .roadmap-list .roadmap-col { width: 100%; }
            /* Minimal prose for the TipTap description. */
            .roadmap-prose p { margin: 0 0 .4rem; }
            .roadmap-prose ul { list-style: disc; padding-left: 1.25rem; margin: 0 0 .4rem; }
            .roadmap-prose ol { list-style: decimal; padding-left: 1.25rem; margin: 0 0 .4rem; }
            .roadmap-prose h3 { font-weight: 700; font-size: .95rem; margin: .2rem 0 .3rem; }
            .roadmap-prose a { color: #7c3aed; text-decoration: underline; }
            .roadmap-prose:empty::before, .roadmap-prose .is-editor-empty:first-child::before { content: attr(data-placeholder); color: #9ca3af; }
        </style>
    @endonce

    @if($errorMessage)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">
            {{ $errorMessage }}
        </div>
    @endif

    @if($canManage)
        {{-- Toolbar: Kanban/List segmented toggle (Material 3) + compact FAB --}}
        <div class="flex items-center justify-between gap-2">
            <div class="inline-flex items-center rounded-full border border-gray-200 bg-gray-100 p-0.5 dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="{{ __('loops.roadmap_view_toggle') }}">
                <button type="button" x-on:click="mode = 'kanban'"
                        x-bind:class="mode === 'kanban' ? 'bg-white text-violet-700 shadow-sm dark:bg-gray-700 dark:text-violet-200' : 'text-gray-500 dark:text-gray-400'"
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition" x-bind:aria-pressed="mode === 'kanban'">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v12a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18V6ZM13.5 6A2.25 2.25 0 0 1 15.75 3.75H18A2.25 2.25 0 0 1 20.25 6v6A2.25 2.25 0 0 1 18 14.25h-2.25A2.25 2.25 0 0 1 13.5 12V6Z"/></svg>
                    <span class="hidden sm:inline">{{ __('loops.roadmap_view_kanban') }}</span>
                </button>
                <button type="button" x-on:click="mode = 'list'"
                        x-bind:class="mode === 'list' ? 'bg-white text-violet-700 shadow-sm dark:bg-gray-700 dark:text-violet-200' : 'text-gray-500 dark:text-gray-400'"
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition" x-bind:aria-pressed="mode === 'list'">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                    <span class="hidden sm:inline">{{ __('loops.roadmap_view_list') }}</span>
                </button>
            </div>

            <div class="flex items-center gap-2">
                @if($archivedCount > 0)
                    <button type="button" x-on:click="archivesOpen = true"
                            class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                        {{ __('loops.roadmap_archives') }} ({{ $archivedCount }})
                    </button>
                @endif

            {{-- Compact FAB — sits just left of the panel "expand" control --}}
            <button type="button" x-on:click="openCreate(@js(\App\Models\LoopRoadmapItem::STATUS_TODO))"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-violet-600 text-white shadow-md transition hover:bg-violet-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500"
                    title="{{ __('loops.roadmap_add_action') }}" aria-label="{{ __('loops.roadmap_add_action') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            </button>
            </div>
        </div>

        @if($items->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-900">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
                <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.roadmap.empty_title') }}</h3>
                <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_empty_pitch') }}</p>
            </div>
        @else
            {{-- Board: same DOM for both modes; layout switches via container class --}}
            <div x-bind:class="mode === 'kanban' ? 'roadmap-board pb-1' : 'roadmap-list'">
                @foreach($columns as $status => $colItems)
                    <section class="roadmap-col flex flex-col rounded-2xl border p-2 {{ $statusMeta[$status]['col'] }}">
                        <div class="mb-2 flex items-center justify-between px-1">
                            <p class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide {{ $statusMeta[$status]['chip'] }}">
                                <span class="h-2 w-2 rounded-full {{ $statusMeta[$status]['dot'] }}"></span>
                                {{ __('loops.'.$statusMeta[$status]['label']) }}
                                <span class="rounded-full bg-white/70 px-1.5 text-[10px] text-gray-600 dark:bg-gray-900/50 dark:text-gray-300">{{ $colItems->count() }}</span>
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

            <p class="flex flex-wrap items-center justify-center gap-x-2 gap-y-0.5 text-center text-[11px] text-gray-400 dark:text-gray-500">
                <span>{{ trans_choice('loops.roadmap_open_count', $openCount, ['count' => $openCount]) }}</span>
                @if($lastUpdated)
                    <span aria-hidden="true">·</span>
                    <span>{{ __('loops.roadmap_last_updated', ['date' => $lastUpdated->isoFormat('D MMM · HH:mm')]) }}</span>
                @endif
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
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_assignees') }}</label>
                            @include('livewire.partials.assignee-picker', ['model' => 'newAssignees', 'options' => $members])
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

    {{-- Premium detail card --}}
    @if($detail)
        @include('livewire.partials.roadmap-detail')
    @endif

    {{-- Archives panel --}}
    @if($canManage)
        <div x-show="archivesOpen" x-cloak class="fixed inset-0 z-[66] flex items-stretch justify-center sm:items-center sm:p-4"
             role="dialog" aria-modal="true" aria-label="{{ __('loops.roadmap_archives') }}" x-on:keydown.escape.window="archivesOpen = false">
            <div class="absolute inset-0 bg-black/50" x-on:click="archivesOpen = false"></div>
            <div class="relative flex h-full max-h-full w-full flex-col overflow-hidden bg-white shadow-2xl dark:bg-gray-900 sm:h-auto sm:max-h-[80dvh] sm:max-w-lg sm:rounded-2xl">
                <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <p class="inline-flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                        {{ __('loops.roadmap_archives') }}
                    </p>
                    <button type="button" x-on:click="archivesOpen = false" aria-label="{{ __('loops.roadmap_close') }}"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
                    @forelse($archivedItems as $arch)
                        <div wire:key="arch-{{ $arch->id }}" class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700 dark:bg-gray-800/40">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">{{ $arch->title }}</p>
                                <p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                                    {{ __('loops.roadmap_archived_from', ['status' => __('loops.'.$statusMeta[$arch->status]['label'])]) }}
                                    · {{ __('loops.roadmap_archived_on', ['date' => $arch->deleted_at?->isoFormat('D MMM · HH:mm')]) }}
                                </p>
                            </div>
                            <button type="button" wire:click="restoreItem('{{ $arch->id }}')"
                                    class="shrink-0 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700">{{ __('loops.roadmap_restore') }}</button>
                            @if($isPrivileged)
                                <button type="button" title="{{ __('loops.roadmap_delete_permanently') }}" aria-label="{{ __('loops.roadmap_delete_permanently') }}"
                                        x-on:click="$dispatch('open-confirm', { title: @js(__('loops.roadmap_delete_permanently_title')), body: @js(__('loops.roadmap_delete_permanently_body')), confirmLabel: @js(__('loops.roadmap_delete_permanently')), danger: true, accept: 'forceDeleteItem', params: ['{{ $arch->id }}'] })"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                </button>
                            @endif
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-gray-400 dark:text-gray-500">{{ __('loops.roadmap_no_archives') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- Reusable premium confirmation modal (delete, add-and-assign) --}}
    <x-confirm-dialog />
</div>
