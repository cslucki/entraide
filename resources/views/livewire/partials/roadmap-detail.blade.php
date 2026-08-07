{{-- Premium detail card (digital-kanban best practices, Trello-inspired).
     Left: title, a compact action bar (Labels / Dates / Members as Alpine popovers),
     a summary of what's set, then the Description (read-only by default, edit on demand).
     Right: comments & activity. Uses $detail, $detailCanModify, $members, $loopLabels,
     $palette, $statusMeta, $detailMessages, $isPrivileged. --}}
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
    $overdue = $detail->due_at && ! $detail->isDone() && $detail->due_at->isPast();
@endphp
<div class="fixed inset-0 z-[68] flex items-stretch justify-center sm:items-center sm:p-4"
     role="dialog" aria-modal="true" aria-labelledby="roadmap-detail-title"
     x-data="{ init() { document.body.style.overflow = 'hidden'; }, destroy() { document.body.style.overflow = ''; } }"
     x-on:keydown.escape.window="$wire.closeDetail()">
    <div class="absolute inset-0 bg-black/50" x-on:click="$wire.closeDetail()"></div>

    <div class="relative flex h-full max-h-full w-full flex-col overflow-hidden bg-white shadow-2xl dark:bg-gray-900 sm:h-auto sm:max-h-[90dvh] sm:max-w-4xl sm:rounded-2xl">
        {{-- Header: status dropdown (left) + close (right) --}}
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            @if($detailCanModify)
                <div x-data="{ open: false }" class="relative">
                    <button type="button" x-on:click="open = !open"
                            class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                        <span class="h-2 w-2 rounded-full {{ $statusMeta[$detail->status]['dot'] }}"></span>
                        {{ $statusMeta[$detail->status]['label'] }}
                        <svg class="h-3.5 w-3.5 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
                         class="absolute left-0 z-40 mt-1 w-44 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800" role="menu">
                        @foreach(\App\Models\LoopRoadmapItem::STATUSES as $st)
                            <button type="button" wire:click="setStatus('{{ $detail->id }}', '{{ $st }}')" x-on:click="open = false"
                                    @class(['flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs font-medium transition hover:bg-gray-50 dark:hover:bg-gray-700/60', 'text-violet-700 dark:text-violet-300' => $detail->status === $st, 'text-gray-700 dark:text-gray-200' => $detail->status !== $st])>
                                <span class="h-2 w-2 rounded-full {{ $statusMeta[$st]['dot'] }}"></span>
                                {{ $statusMeta[$st]['label'] }}
                                @if($detail->status === $st)<svg class="ml-auto h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>@endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    <span class="h-2 w-2 rounded-full {{ $statusMeta[$detail->status]['dot'] }}"></span>
                    {{ $statusMeta[$detail->status]['label'] }}
                </span>
            @endif

            <button type="button" wire:click="closeDetail" aria-label="{{ __('loops.roadmap_close') }}"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">
            {{-- LEFT --}}
            <div class="min-w-0 flex-1 space-y-4 overflow-y-auto p-4 lg:p-5">
                @if($errorMessage)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">{{ $errorMessage }}</div>
                @endif

                {{-- Title --}}
                @if($detailCanModify)
                    <input wire:model="detailTitle" type="text" maxlength="255" id="roadmap-detail-title"
                           x-on:keydown.enter.prevent="$el.blur()" wire:blur="saveDetailTitle"
                           class="w-full border-0 bg-transparent p-0 text-xl font-bold text-gray-900 focus:ring-0 dark:text-gray-100">
                @else
                    <h2 id="roadmap-detail-title" class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $detail->title }}</h2>
                @endif
                <p class="-mt-2 flex flex-wrap items-center gap-x-2 text-[11px] text-gray-400 dark:text-gray-500">
                    @if($detail->creator)<span>{{ __('loops.roadmap_created_by_name', ['name' => $detail->creator->publicDisplayName()]) }}</span>@endif
                    <span>·</span><span>{{ __('loops.roadmap_created_on', ['date' => $detail->created_at?->isoFormat('D MMM Y')]) }}</span>
                </p>

                {{-- Action bar: Labels / Dates / Members (each a compact Alpine popover) --}}
                @if($detailCanModify)
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Labels --}}
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" x-on:click="open = !open" class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/></svg>
                                {{ __('loops.roadmap_labels') }}
                            </button>
                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-on:keydown.escape="open = false" x-transition.opacity
                                 class="absolute left-0 z-40 mt-1 w-64 rounded-xl border border-gray-200 bg-white p-2 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($loopLabels as $label)
                                        @php $on = in_array($label->id, $attachedLabelIds, true); @endphp
                                        <button type="button" wire:click="toggleLabel('{{ $detail->id }}', '{{ $label->id }}')"
                                                @class(['rounded px-2 py-0.5 text-[11px] font-semibold transition', ($labelColors[$label->color] ?? $labelColors['gray']) => $on, 'bg-gray-50 text-gray-400 ring-1 ring-inset ring-gray-200 hover:text-gray-600 dark:bg-gray-900 dark:text-gray-500 dark:ring-gray-700' => ! $on])>{{ $label->name }}</button>
                                    @endforeach
                                    @if($loopLabels->isEmpty())<span class="text-[11px] text-gray-400">{{ __('loops.roadmap_no_labels') }}</span>@endif
                                </div>
                                <div x-data="{ form: false }" class="mt-2 border-t border-gray-100 pt-2 dark:border-gray-700">
                                    <button type="button" x-on:click="form = !form" class="text-[11px] font-semibold text-violet-600 hover:text-violet-800 dark:text-violet-300">+ {{ __('loops.roadmap_add_label') }}</button>
                                    <div x-show="form" x-cloak class="mt-2 space-y-2">
                                        <input wire:model="newLabelName" type="text" maxlength="40" placeholder="{{ __('loops.roadmap_label_name') }}" class="w-full rounded-lg border-gray-300 bg-white text-xs dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                                        <div class="flex flex-wrap items-center gap-1">
                                            @foreach($palette as $c)
                                                <button type="button" wire:click="$set('newLabelColor', '{{ $c }}')" aria-label="{{ $c }}" @class(['h-5 w-5 rounded-full transition', $swatch[$c], 'ring-2 ring-offset-1 ring-gray-800 dark:ring-white' => $newLabelColor === $c])></button>
                                            @endforeach
                                        </div>
                                        <button type="button" wire:click="createLabel" class="w-full rounded-lg bg-violet-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-violet-700">{{ __('loops.roadmap_create_label') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Dates --}}
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" x-on:click="open = !open" class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75"/></svg>
                                {{ __('loops.roadmap_field_due') }}
                            </button>
                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
                                 class="absolute left-0 z-40 mt-1 w-56 rounded-xl border border-gray-200 bg-white p-3 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                                <input type="date" wire:model="detailDueAt" wire:change="saveDetailDue" class="w-full rounded-lg border-gray-300 bg-white text-xs dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200">
                            </div>
                        </div>

                        {{-- Members --}}
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" x-on:click="open = !open" class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2.5 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                                {{ __('loops.roadmap_assignees') }}
                            </button>
                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
                                 class="absolute left-0 z-40 mt-1 w-64 rounded-xl border border-gray-200 bg-white p-3 shadow-xl dark:border-gray-700 dark:bg-gray-800">
                                @include('livewire.partials.assignee-picker', ['model' => 'detailAssignees', 'options' => $members, 'change' => 'assign', 'changeArg' => $detail->id])
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Summary chips: what's actually set --}}
                @if($detail->labels->isNotEmpty() || $detail->assignees->isNotEmpty() || $detail->due_at)
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach($detail->labels as $label)
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold {{ $labelColors[$label->color] ?? $labelColors['gray'] }}">{{ $label->name }}</span>
                        @endforeach
                        @if($detail->assignees->isNotEmpty())
                            <span class="flex -space-x-1.5">
                                @foreach($detail->assignees as $a)
                                    @php $photo = $a->isDisplayableIn(currentOrganization()) ? $a->avatar_url : null; @endphp
                                    @if($photo)<img src="{{ $photo }}" alt="" class="h-6 w-6 rounded-full border border-white object-cover dark:border-gray-900" title="{{ $a->publicDisplayName() }}">
                                    @else<span title="{{ $a->publicDisplayName() }}" class="flex h-6 w-6 items-center justify-center rounded-full border border-white bg-violet-200 text-[10px] font-bold text-violet-800 dark:border-gray-900 dark:bg-violet-500/30 dark:text-violet-100">{{ mb_strtoupper(mb_substr($a->publicDisplayName(), 0, 1)) }}</span>@endif
                                @endforeach
                            </span>
                        @endif
                        @if($detail->due_at)
                            <span @class(['inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] dark:bg-gray-800', 'text-red-600 dark:text-red-400 font-semibold' => $overdue, 'text-gray-600 dark:text-gray-300' => ! $overdue])>
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75"/></svg>
                                {{ $detail->due_at->isoFormat('D MMM Y') }}
                            </span>
                        @endif
                    </div>
                @endif

                {{-- Description: read-only by default, edit on demand --}}
                <div x-data="{ editing: false }">
                    <div class="mb-1.5 flex items-center justify-between">
                        <p class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
                            {{ __('loops.roadmap_description') }}
                        </p>
                        @if($detailCanModify)
                            <button type="button" x-show="!editing" x-on:click="editing = true; $nextTick(() => { document.dispatchEvent(new CustomEvent('bp:markdown-editor:init')); setTimeout(() => document.dispatchEvent(new CustomEvent('bp:markdown-editor:init')), 250); })"
                                    class="rounded-lg border border-gray-200 px-2.5 py-1 text-[11px] font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">{{ __('loops.roadmap_edit') }}</button>
                        @endif
                    </div>

                    {{-- Read-only view --}}
                    <div x-show="!editing">
                        @if(filled($detail->description))
                            <div class="roadmap-prose rounded-xl bg-gray-50 p-3 text-sm text-gray-800 dark:bg-gray-800/40 dark:text-gray-100">
                                {!! \Illuminate\Support\Str::markdown($detail->description, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                            </div>
                        @elseif($detailCanModify)
                            <button type="button" x-on:click="editing = true; $nextTick(() => document.dispatchEvent(new CustomEvent('bp:markdown-editor:init')))"
                                    class="w-full rounded-xl bg-gray-50 p-3 text-left text-sm text-gray-400 transition hover:bg-gray-100 dark:bg-gray-800/40 dark:hover:bg-gray-800">{{ __('loops.roadmap_description_placeholder') }}</button>
                        @else
                            <p class="text-[11px] text-gray-400">—</p>
                        @endif
                    </div>

                    {{-- Editor --}}
                    @if($detailCanModify)
                        <template x-if="editing">
                            <div wire:key="desc-{{ $detail->id }}" wire:ignore
                                 x-data="{ save() { const ta = this.$root.querySelector('textarea[data-tiptap-target]'); if (ta) $wire.saveDescription(ta.value); } }"
                                 x-init="$nextTick(() => { document.dispatchEvent(new CustomEvent('bp:markdown-editor:init')); setTimeout(() => document.dispatchEvent(new CustomEvent('bp:markdown-editor:init')), 250); })">
                                <x-markdown-wysiwyg-editor name="roadmap-desc-{{ $detail->id }}" :value="$detail->description ?? ''" :placeholder="__('loops.roadmap_description_placeholder')" rows="5" />
                                <div class="mt-2 flex items-center justify-end gap-2">
                                    <button type="button" x-on:click="editing = false" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800">{{ __('loops.cancel') }}</button>
                                    <button type="button" x-on:click="$root.querySelector('textarea[data-tiptap-target]') && $wire.saveDescription($root.querySelector('textarea[data-tiptap-target]').value); editing = false"
                                            class="rounded-lg bg-violet-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700">{{ __('loops.roadmap_validate') }}</button>
                                </div>
                            </div>
                        </template>
                    @endif
                </div>

                {{-- Attachments — reserved for a future task --}}
                <div>
                    <p class="mb-1.5 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/></svg>
                        {{ __('loops.roadmap_attachments') }}
                    </p>
                    <div class="rounded-xl border border-dashed border-gray-300 p-3 text-center text-[11px] text-gray-400 dark:border-gray-700 dark:text-gray-500">{{ __('loops.roadmap_attachments_soon') }}</div>
                </div>
            </div>

            {{-- RIGHT: comments & activity --}}
            <div class="shrink-0 space-y-3 border-t border-gray-100 bg-gray-50/60 p-4 dark:border-gray-800 dark:bg-gray-800/30 lg:w-80 lg:overflow-y-auto lg:border-l lg:border-t-0">
                <p class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/></svg>
                    {{ __('loops.roadmap_comments') }}
                </p>
                <div wire:key="thread-{{ $detail->id }}">
                    @include('livewire.partials.roadmap-thread')
                </div>
            </div>
        </div>

        {{-- Footer: archive (bottom-right) --}}
        @if($detailCanModify)
            <div class="flex shrink-0 items-center justify-end border-t border-gray-100 px-4 py-2.5 dark:border-gray-800">
                <button type="button"
                        x-on:click="$dispatch('open-confirm', { title: @js(__('loops.roadmap_archive_title')), body: @js(__('loops.roadmap_archive_body')), confirmLabel: @js(__('loops.roadmap_archive')), accept: 'archiveItem', params: ['{{ $detail->id }}'] })"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 dark:border-gray-700 dark:text-gray-300 dark:hover:border-red-800 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                    {{ __('loops.roadmap_archive') }}
                </button>
            </div>
        @endif
    </div>
</div>
