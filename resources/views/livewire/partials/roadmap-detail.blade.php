{{-- Premium detail card (Trello-like). Desktop: two zones (content / activity).
     Mobile: full screen. Uses $detail, $detailCanModify, $members, $orgCandidates,
     $canAddMembers, $loopLabels, $palette, $statusMeta. --}}
@php
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
    $swatch = [
        'violet' => 'bg-violet-500', 'blue' => 'bg-blue-500', 'cyan' => 'bg-cyan-500', 'green' => 'bg-green-500',
        'yellow' => 'bg-yellow-500', 'orange' => 'bg-orange-500', 'red' => 'bg-red-500', 'pink' => 'bg-pink-500', 'gray' => 'bg-gray-500',
    ];
    $attachedLabelIds = $detail->labels->pluck('id')->all();
@endphp
<div class="fixed inset-0 z-[68] flex items-stretch justify-center sm:items-center sm:p-4"
     role="dialog" aria-modal="true" aria-labelledby="roadmap-detail-title"
     x-data="{ init() { document.body.style.overflow = 'hidden'; }, destroy() { document.body.style.overflow = ''; } }"
     x-on:keydown.escape.window="$wire.closeDetail()">
    <div class="absolute inset-0 bg-black/50" x-on:click="$wire.closeDetail()"></div>

    <div class="relative flex h-full max-h-full w-full flex-col overflow-hidden bg-white shadow-2xl dark:bg-gray-900 sm:h-auto sm:max-h-[90dvh] sm:max-w-4xl sm:rounded-2xl">
        {{-- Header (sticky) --}}
        <div class="flex shrink-0 items-start gap-3 border-b border-gray-100 p-4 dark:border-gray-800">
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('loops.cards.roadmap.label') }}</p>
                @if($detailCanModify)
                    <input wire:model="detailTitle" type="text" maxlength="255" id="roadmap-detail-title"
                           x-on:keydown.enter.prevent="$el.blur()" wire:blur="saveDetailTitle" wire:keydown.enter="saveDetailTitle"
                           class="mt-0.5 w-full border-0 bg-transparent p-0 text-lg font-bold text-gray-900 focus:ring-0 dark:text-gray-100">
                @else
                    <h2 id="roadmap-detail-title" class="mt-0.5 text-lg font-bold text-gray-900 dark:text-gray-100">{{ $detail->title }}</h2>
                @endif
            </div>
            @unless($detail->isDone())
                @if($detailCanModify)
                    <button type="button" wire:click="setStatus('{{ $detail->id }}', 'done')"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        {{ __('loops.roadmap_mark_done') }}
                    </button>
                @endif
            @endunless
            <button type="button" wire:click="closeDetail" aria-label="{{ __('loops.roadmap_close') }}"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Body: content (left) + activity (right) --}}
        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">
            {{-- LEFT --}}
            <div class="min-w-0 flex-1 space-y-5 overflow-y-auto p-4 lg:p-5">
                @if($errorMessage)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">{{ $errorMessage }}</div>
                @endif

                {{-- Status + meta chips --}}
                <div class="flex flex-wrap items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2 py-1 font-semibold dark:bg-gray-800">
                        <span class="h-2 w-2 rounded-full {{ $statusMeta[$detail->status]['dot'] }}"></span>
                        {{ __('loops.'.$statusMeta[$detail->status]['label']) }}
                    </span>
                    @if($detail->creator)<span>{{ __('loops.roadmap_created_by_name', ['name' => $detail->creator->publicDisplayName()]) }}</span>@endif
                    <span>{{ __('loops.roadmap_created_on', ['date' => $detail->created_at?->isoFormat('D MMM Y')]) }}</span>
                </div>

                {{-- Labels --}}
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_labels') }}</p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach($loopLabels as $label)
                            @php $on = in_array($label->id, $attachedLabelIds, true); @endphp
                            <button type="button" @if($detailCanModify) wire:click="toggleLabel('{{ $detail->id }}', '{{ $label->id }}')" @else disabled @endif
                                    @class([
                                        'rounded px-2 py-0.5 text-[11px] font-semibold transition',
                                        ($labelColors[$label->color] ?? $labelColors['gray']) => $on,
                                        'bg-gray-50 text-gray-400 ring-1 ring-inset ring-gray-200 hover:text-gray-600 dark:bg-gray-800 dark:text-gray-500 dark:ring-gray-700' => ! $on,
                                    ])>{{ $label->name }}</button>
                        @endforeach
                        @if($loopLabels->isEmpty())
                            <span class="text-[11px] text-gray-400">{{ __('loops.roadmap_no_labels') }}</span>
                        @endif
                    </div>
                    {{-- Create label --}}
                    @if($detailCanModify)
                        <div x-data="{ open: false }" class="mt-2">
                            <button type="button" x-on:click="open = !open" class="text-[11px] font-semibold text-violet-600 hover:text-violet-800 dark:text-violet-300">+ {{ __('loops.roadmap_add_label') }}</button>
                            <div x-show="open" x-cloak class="mt-2 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 p-2 dark:border-gray-700">
                                <input wire:model="newLabelName" type="text" maxlength="40" placeholder="{{ __('loops.roadmap_label_name') }}"
                                       class="min-w-0 flex-1 rounded-lg border-gray-300 bg-white text-xs dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                                <div class="flex items-center gap-1">
                                    @foreach($palette as $c)
                                        <button type="button" wire:click="$set('newLabelColor', '{{ $c }}')" aria-label="{{ $c }}"
                                                @class(['h-5 w-5 rounded-full transition', $swatch[$c], 'ring-2 ring-offset-1 ring-gray-800 dark:ring-white' => $newLabelColor === $c])></button>
                                    @endforeach
                                </div>
                                <button type="button" wire:click="createLabel" class="rounded-lg bg-violet-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-violet-700">{{ __('loops.roadmap_create_label') }}</button>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Description (reuses the markdown WYSIWYG editor from services/create) --}}
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_description') }}</p>
                    @if($detailCanModify)
                        <div wire:key="desc-{{ $detail->id }}" wire:ignore
                             x-data="{ saved: false, save() { const ta = this.$root.querySelector('textarea[data-tiptap-target]'); if (ta) { $wire.saveDescription(ta.value); this.saved = true; setTimeout(() => this.saved = false, 1800); } } }"
                             x-init="$nextTick(() => document.dispatchEvent(new CustomEvent('bp:markdown-editor:init')))">
                            <x-markdown-wysiwyg-editor
                                name="roadmap-desc-{{ $detail->id }}"
                                :value="$detail->description ?? ''"
                                :placeholder="__('loops.roadmap_description_placeholder')"
                                rows="4" />
                            <div class="mt-2 flex items-center justify-end gap-2">
                                <span x-show="saved" x-cloak x-transition.opacity class="text-[11px] font-medium text-emerald-600 dark:text-emerald-400">{{ __('loops.roadmap_saved') }}</span>
                                <button type="button" x-on:click="save()"
                                        class="rounded-lg bg-violet-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700">{{ __('loops.roadmap_validate') }}</button>
                            </div>
                        </div>
                    @elseif(filled($detail->description))
                        <div class="roadmap-prose rounded-xl border border-gray-200 p-3 text-sm text-gray-800 dark:border-gray-700 dark:text-gray-100">
                            {!! \Illuminate\Support\Str::markdown($detail->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                    @else
                        <p class="text-[11px] text-gray-400">—</p>
                    @endif
                </div>
            </div>

            {{-- RIGHT: activity / metadata --}}
            <div class="shrink-0 space-y-5 border-t border-gray-100 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-gray-800/30 lg:w-80 lg:overflow-y-auto lg:border-l lg:border-t-0">
                {{-- Assignees (live) --}}
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_assignees') }}</p>
                    @if($detailCanModify)
                        @include('livewire.partials.assignee-picker', ['model' => 'detailAssignees', 'options' => $members, 'change' => 'assign', 'changeArg' => $detail->id])
                    @else
                        <div class="flex flex-wrap gap-1.5">
                            @forelse($detail->assignees as $a)
                                <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-xs text-violet-800 dark:bg-violet-500/20 dark:text-violet-200">{{ $a->publicDisplayName() }}</span>
                            @empty
                                <span class="text-[11px] text-gray-400">{{ __('loops.roadmap_assign_none') }}</span>
                            @endforelse
                        </div>
                    @endif
                </div>

                {{-- Due date --}}
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_field_due') }}</p>
                    @if($detailCanModify)
                        <input type="date" wire:model="detailDueAt" wire:change="saveDetailDue"
                               class="w-full rounded-lg border-gray-300 bg-white text-xs dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200">
                    @else
                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $detail->due_at?->isoFormat('D MMM Y') ?? '—' }}</p>
                    @endif
                </div>

                {{-- Comments / activity — implemented in the next checkpoint --}}
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.roadmap_comments') }}</p>
                    <div wire:key="thread-{{ $detail->id }}">
                        @include('livewire.partials.roadmap-thread')
                    </div>
                </div>

                {{-- Attachments — reserved for a future task --}}
                <div class="rounded-xl border border-dashed border-gray-300 p-3 text-center text-[11px] text-gray-400 dark:border-gray-700 dark:text-gray-500">
                    {{ __('loops.roadmap_attachments_soon') }}
                </div>
            </div>
        </div>
    </div>
</div>
