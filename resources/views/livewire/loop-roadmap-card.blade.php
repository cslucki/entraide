<div class="space-y-4"
     data-roadmap-root
     data-roadmap-can-manage="{{ $canManage ? '1' : '0' }}"
     data-roadmap-cross-msg="{{ __('loops.roadmap_cross_group') }}"
     x-data="{ crossMsg: '' }"
     x-on:roadmap-cross-group.window="crossMsg = $event.detail; clearTimeout($el._t); $el._t = setTimeout(() => crossMsg = '', 3500)">
    @once
        <style>
            .roadmap-ghost { opacity: .45; }
            .roadmap-chosen { box-shadow: 0 8px 20px -6px rgba(124,58,237,.35); }
            [data-roadmap-group] .drag-handle { touch-action: none; }
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

    {{-- Transient hint when a cross-group drag is attempted --}}
    <div x-show="crossMsg" x-cloak x-transition.opacity
         class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:border-sky-700/50 dark:bg-sky-900/20 dark:text-sky-200"
         x-text="crossMsg"></div>

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
        {{-- "To do" group — drag & drop confined to this list --}}
        @if($openItems->isNotEmpty())
            <div class="space-y-2">
                <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('loops.roadmap_section_todo') }}</p>
                <ul class="space-y-2" data-roadmap-group data-status="open">
                    @foreach($openItems as $item)
                        @include('livewire.partials.roadmap-item')
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- "Done" group — drag & drop confined to this list --}}
        @if($doneItems->isNotEmpty())
            <div class="space-y-2">
                <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('loops.roadmap_section_done') }}</p>
                <ul class="space-y-2" data-roadmap-group data-status="done">
                    @foreach($doneItems as $item)
                        @include('livewire.partials.roadmap-item')
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-center text-[11px] text-gray-400 dark:text-gray-500">
            {{ trans_choice('loops.roadmap_open_count', $openCount, ['count' => $openCount]) }}
        </p>
    @endif
</div>
