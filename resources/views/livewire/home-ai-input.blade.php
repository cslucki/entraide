<div class="w-full max-w-3xl mx-auto px-4 py-8">
    <div class="relative group" x-data="{ prompt: @entangle('prompt') }">
        {{-- Input Container with atmospheric glow --}}
        <div class="relative flex flex-col items-center bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl border border-white/20 dark:border-gray-700/50 rounded-[2.5rem] shadow-[0_20px_50px_-20px_rgba(139,92,246,0.15)] focus-within:shadow-[0_20px_50px_-20px_rgba(139,92,246,0.3)] transition-all duration-500 overflow-hidden p-2">

            <form wire:submit.prevent="submit" class="w-full flex items-center gap-2 pr-2">
                <textarea
                    x-model="prompt"
                    placeholder="How can Entraide help you today?"
                    class="w-full p-6 bg-transparent border-none focus:ring-0 resize-none overflow-hidden min-h-[72px] max-h-[200px] text-lg text-gray-800 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 font-medium tracking-tight"
                    rows="1"
                    oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"
                    @keydown.enter.prevent="$wire.submit()"
                ></textarea>

                <button
                    type="submit"
                    class="shrink-0 w-12 h-12 flex items-center justify-center bg-indigo-600 hover:bg-indigo-700 text-white rounded-full transition-all duration-300 shadow-lg hover:shadow-indigo-500/30 disabled:opacity-50"
                    wire:loading.attr="disabled"
                >
                    <div wire:loading.remove wire:target="submit">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                        </svg>
                    </div>
                    <div wire:loading wire:target="submit">
                        <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </button>
            </form>
        </div>

        {{-- Suggestion Chips --}}
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            @foreach($suggestions as $suggestion)
                <button
                    type="button"
                    wire:click="setPrompt('{{ $suggestion }}')"
                    class="px-5 py-2.5 bg-gray-100/80 dark:bg-gray-800/40 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium rounded-full border border-gray-200 dark:border-gray-700 hover:border-indigo-200 dark:hover:border-indigo-800 transition-all duration-300 backdrop-blur-sm"
                >
                    {{ $suggestion }}
                </button>
            @endforeach
        </div>

        {{-- Admin Debug Overlay --}}
        @if($debugResult && $isAdmin)
            <div x-data="{ open: true }" x-show="open" class="fixed bottom-6 right-6 z-50 max-w-sm w-full">
                <div class="bg-gray-900/90 dark:bg-black/90 backdrop-blur-2xl rounded-3xl border border-white/10 shadow-2xl overflow-hidden animate-in slide-in-from-bottom-4 duration-500">
                    <div class="flex items-center justify-between p-4 border-b border-white/5 bg-white/5">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></div>
                            <span class="text-[10px] font-bold text-amber-500 uppercase tracking-widest">AI Debugger</span>
                        </div>
                        <button @click="open = false; $wire.set('debugResult', null)" class="text-gray-500 hover:text-white transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="p-6">
                        <pre class="text-[11px] font-mono text-indigo-300 overflow-auto max-h-48 custom-scrollbar">{{ json_encode($debugResult, JSON_PRETTY_PRINT) }}</pre>
                        <div class="mt-4 pt-4 border-t border-white/5 flex items-center justify-between">
                            <p class="text-[9px] text-gray-500 italic">Redirection suppressed.</p>
                            <span class="text-[9px] font-bold text-gray-600 uppercase tracking-tighter">Admin View Only</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
