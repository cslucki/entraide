<div>
    <div class="space-y-4">
        <textarea
            wire:model="prompt"
            placeholder="Type a test prompt (e.g., 'I want to help with Excel')"
            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-indigo-500"
            rows="3"
        ></textarea>

        <button
            wire:click="test"
            wire:loading.attr="disabled"
            class="w-full px-4 py-2 bg-gray-800 dark:bg-gray-700 hover:bg-gray-700 dark:hover:bg-gray-600 text-white text-sm font-medium rounded-lg transition flex items-center justify-center gap-2"
        >
            <span wire:loading.remove wire:target="test">Test AI</span>
            <span wire:loading wire:target="test">Processing...</span>
            <svg wire:loading wire:target="test" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </button>

        @if($result)
            <div class="mt-4 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Structured Output</p>
                <pre class="text-[11px] font-mono text-indigo-600 dark:text-indigo-400 overflow-auto">{{ json_encode($result, JSON_PRETTY_PRINT) }}</pre>
            </div>
        @endif
    </div>
</div>
