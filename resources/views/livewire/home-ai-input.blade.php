<div class="w-full relative" x-data="{ prompt: @entangle('prompt') }">
    <div class="relative max-w-2xl mx-auto group">
        {{-- Subtle background glow when focused --}}
        <div class="absolute -inset-2 bg-indigo-500/5 dark:bg-indigo-400/5 rounded-[2rem] blur-xl opacity-0 group-focus-within:opacity-100 transition duration-700"></div>

        {{-- Main Input Container --}}
        <div class="relative bg-white/80 dark:bg-zinc-900/80 backdrop-blur-md border border-zinc-200 dark:border-zinc-800 rounded-2xl shadow-sm focus-within:shadow-xl focus-within:shadow-indigo-500/5 transition-all duration-300 focus-within:border-indigo-500/30 overflow-hidden">
            <form wire:submit.prevent="submit" class="flex items-end p-2 min-h-[64px]">
                <textarea
                    x-model="prompt"
                    placeholder="Comment pouvons-nous vous aider ?"
                    class="w-full py-3 px-4 bg-transparent border-none focus:ring-0 resize-none overflow-hidden min-h-[44px] max-h-[160px] text-[15px] text-zinc-700 dark:text-zinc-200 placeholder-zinc-400 dark:placeholder-zinc-500 font-medium leading-relaxed"
                    rows="1"
                    oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"
                    @keydown.enter.prevent="$wire.submit()"
                ></textarea>

                <button
                    type="submit"
                    class="shrink-0 w-11 h-11 flex items-center justify-center bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 rounded-xl hover:opacity-90 active:scale-95 transition-all disabled:opacity-30 ml-2 mb-0.5"
                    wire:loading.attr="disabled"
                >
                    <div wire:loading.remove wire:target="submit, selectSuggestion">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </div>
                    <div wire:loading wire:target="submit, selectSuggestion">
                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </button>
            </form>
        </div>
    </div>

    {{-- Suggestion Chips --}}
    <div class="mt-8 flex flex-wrap justify-center gap-2 px-4">
        @foreach($suggestions as $suggestion)
            <button
                type="button"
                wire:click="selectSuggestion('{{ addslashes($suggestion) }}')"
                wire:loading.attr="disabled"
                class="px-4 py-2 bg-white/50 dark:bg-zinc-900/50 text-zinc-500 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white text-[11px] font-bold uppercase tracking-wider rounded-xl border border-zinc-200/60 dark:border-zinc-800/60 hover:border-zinc-300 dark:hover:border-zinc-700 transition-all duration-200 shadow-sm disabled:opacity-50"
            >
                {{ $suggestion }}
            </button>
        @endforeach
    </div>

    {{-- Debug Overlay --}}
    @if($debugResult && $isAdmin)
        <div x-data="{ open: true }" x-show="open" class="fixed bottom-6 right-6 z-50 max-w-xs w-full animate-in slide-in-from-bottom-4 duration-500">
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-2xl overflow-hidden">
                <div class="flex items-center justify-between p-3 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50">
                    <span class="text-[9px] font-bold text-zinc-400 uppercase tracking-widest">Classification Debug</span>
                    <button @click="open = false; $wire.set('debugResult', null)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="p-4">
                    <pre class="text-[10px] font-mono text-indigo-600 dark:text-indigo-400 bg-zinc-50 dark:bg-zinc-950 p-3 rounded-lg overflow-auto max-h-40">{{ json_encode($debugResult, JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>
        </div>
    @endif
</div>
