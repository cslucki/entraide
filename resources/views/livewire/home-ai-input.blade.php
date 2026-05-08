<div class="w-full py-10 px-4">
    <div class="max-w-4xl mx-auto">
        {{-- Input Section --}}
        <div class="relative" x-data="{
            prompt: @entangle('prompt'),
            placeholder: 'Que souhaitez-vous faire aujourd’hui ?',
            placeholders: [
                'Je veux proposer un micro-service en informatique...',
                'J’ai besoin d’aide pour mon déménagement...',
                'Je veux écrire un article sur le télétravail...',
                'Je cherche des évènements networking à Paris...'
            ],
            index: 0,
            init() {
                setInterval(() => {
                    this.index = (this.index + 1) % this.placeholders.length;
                    this.placeholder = this.placeholders[this.index];
                }, 4000);
            }
        }">
            <div class="bg-white dark:bg-zinc-800 rounded-[2rem] shadow-xl shadow-indigo-500/5 border border-gray-100 dark:border-zinc-700 p-2 focus-within:ring-2 focus-within:ring-indigo-500/20 transition-all duration-300">
                <form wire:submit.prevent="submit" class="flex items-end">
                    <textarea
                        x-model="prompt"
                        :placeholder="placeholder"
                        class="w-full py-4 px-6 bg-transparent border-none focus:ring-0 resize-none text-lg text-gray-800 dark:text-gray-100 placeholder-gray-400 dark:placeholder-zinc-500 leading-relaxed min-h-[60px] max-h-[240px]"
                        rows="1"
                        oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"
                        @keydown.enter.prevent="if (!event.shiftKey) $wire.submit()"
                    ></textarea>

                    <div class="pb-2 pr-2">
                        <button
                            type="submit"
                            class="w-12 h-12 flex items-center justify-center bg-indigo-600 text-white rounded-2xl hover:bg-indigo-700 active:scale-95 transition-all disabled:opacity-50 shadow-lg shadow-indigo-200 dark:shadow-none"
                            wire:loading.attr="disabled"
                        >
                            <div wire:loading.remove wire:target="submit, selectSuggestion">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </div>
                            <div wire:loading wire:target="submit, selectSuggestion">
                                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Suggestions --}}
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <button type="button" wire:click="selectSuggestion('Je veux proposer un micro-service')" class="px-5 py-2.5 bg-white dark:bg-zinc-800 border border-gray-100 dark:border-zinc-700 rounded-full text-sm font-bold text-gray-600 dark:text-zinc-400 hover:border-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition shadow-sm">
                    🚀 Proposer un service
                </button>
                <button type="button" wire:click="selectSuggestion('J\'ai besoin d\'aide')" class="px-5 py-2.5 bg-white dark:bg-zinc-800 border border-gray-100 dark:border-zinc-700 rounded-full text-sm font-bold text-gray-600 dark:text-zinc-400 hover:border-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition shadow-sm">
                    🤝 Demander de l'aide
                </button>
                <button type="button" wire:click="selectSuggestion('Je cherche des membres')" class="px-5 py-2.5 bg-white dark:bg-zinc-800 border border-gray-100 dark:border-zinc-700 rounded-full text-sm font-bold text-gray-600 dark:text-zinc-400 hover:border-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition shadow-sm">
                    🔍 Explorer
                </button>
            </div>
        </div>

        {{-- Debug Overlay (Admin Only) --}}
        @if($debugResult && $isAdmin)
            <div x-data="{ open: true }" x-show="open" class="mt-12 bg-zinc-900 rounded-2xl p-6 border border-zinc-700 animate-in fade-in slide-in-from-bottom-4">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-xs font-black text-indigo-500 uppercase tracking-widest">AI Intent Debug</span>
                    <button @click="open = false; $wire.set('debugResult', null)" class="text-zinc-500 hover:text-white">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <pre class="text-sm font-mono text-emerald-400 overflow-auto max-h-60">@json($debugResult, JSON_PRETTY_PRINT)</pre>
            </div>
        @endif
    </div>
</div>
