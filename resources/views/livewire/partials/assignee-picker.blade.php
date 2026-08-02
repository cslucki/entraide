{{-- Searchable multi-assignee picker (max 3), Material 3 inspired: input chips +
     filter field + option list. Bound to a Livewire array property via entangle.
     Params: $model (string property name), $options (collection of ['id','name']). --}}
<div x-data="{
        selected: @entangle($model),
        q: '',
        open: false,
        options: {{ \Illuminate\Support\Js::from($options->map(fn ($o) => ['id' => (string) $o['id'], 'name' => $o['name']])->values()) }},
        max: {{ \App\Models\LoopRoadmapItem::MAX_ASSIGNEES }},
        get chips() { return this.options.filter(o => this.selected.map(String).includes(o.id)); },
        get filtered() {
            const q = this.q.toLowerCase().trim();
            return this.options.filter(o => !this.selected.map(String).includes(o.id) && (q === '' || o.name.toLowerCase().includes(q)));
        },
        get full() { return this.selected.length >= this.max; },
        add(id) { if (this.full) return; if (!this.selected.map(String).includes(String(id))) this.selected.push(String(id)); this.q = ''; },
        remove(id) { this.selected = this.selected.filter(x => String(x) !== String(id)); },
     }"
     class="relative">
    {{-- Selected chips --}}
    <div class="mb-1.5 flex flex-wrap gap-1.5" x-show="chips.length">
        <template x-for="c in chips" :key="c.id">
            <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 py-0.5 pl-1 pr-1.5 text-xs font-medium text-violet-800 dark:bg-violet-500/20 dark:text-violet-200">
                <span class="flex h-4 w-4 items-center justify-center rounded-full bg-violet-300 text-[9px] font-bold text-violet-900 dark:bg-violet-500/40 dark:text-violet-100" x-text="c.name.charAt(0).toUpperCase()"></span>
                <span x-text="c.name"></span>
                <button type="button" x-on:click="remove(c.id)" class="ml-0.5 rounded-full p-0.5 hover:bg-violet-200 dark:hover:bg-violet-500/30" aria-label="✕">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </span>
        </template>
    </div>

    {{-- Search field --}}
    <input type="text" x-model="q" x-on:focus="open = true" x-on:click="open = true"
           x-bind:disabled="full"
           placeholder="{{ __('loops.roadmap_search_member') }}"
           class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 placeholder-gray-400 focus:border-violet-500 focus:ring-violet-500 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">

    {{-- Options dropdown --}}
    <div x-show="open && !full" x-cloak x-on:click.outside="open = false" x-transition.opacity
         class="absolute z-40 mt-1 max-h-44 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
        <template x-for="o in filtered" :key="o.id">
            <button type="button" x-on:click="add(o.id)"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-violet-200 text-[10px] font-bold text-violet-800 dark:bg-violet-500/30 dark:text-violet-100" x-text="o.name.charAt(0).toUpperCase()"></span>
                <span class="truncate" x-text="o.name"></span>
            </button>
        </template>
        <p x-show="filtered.length === 0" class="px-3 py-2 text-xs text-gray-400">{{ __('loops.roadmap_no_match') }}</p>
    </div>
    <p x-show="full" class="mt-1 text-[11px] text-gray-400">{{ __('loops.roadmap_max_assignees') }}</p>
</div>
