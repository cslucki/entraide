<x-admin-layout title="AI Orchestration">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <form method="POST" action="{{ route('admin.ai.update') }}" class="space-y-6">
                @csrf

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Core Configuration</h2>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="ai_enabled" value="1" {{ $config['ai_enabled'] ? 'checked' : '' }} class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Enabled</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Provider</label>
                            <select name="ai_provider" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-indigo-500">
                                <option value="fake" {{ $config['ai_provider'] === 'fake' ? 'selected' : '' }}>Fake Provider (Local)</option>
                                <option value="openai" {{ $config['ai_provider'] === 'openai' ? 'selected' : '' }}>OpenAI</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">OpenAI Model</label>
                            <input type="text" name="ai_openai_model" value="{{ $config['ai_openai_model'] }}" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    @if($config['ai_provider'] === 'fake')
                    <div class="p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg flex gap-3 text-amber-700 dark:text-amber-400 text-sm">
                        <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <p><strong>Warning:</strong> Currently using Fake Provider. Real LLM calls are disabled. Behavior is simulated via PHP rules.</p>
                    </div>
                    @endif
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                    <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Prompts Architecture</h2>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Master System Prompt</label>
                        <textarea name="ai_master_prompt" rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-indigo-500">{{ $config['ai_master_prompt'] }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">Defines the overall persona and context.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Classification Instructions</label>
                        <textarea name="ai_classification_prompt" rows="8" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-indigo-500">{{ $config['ai_classification_prompt'] }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">Rules for intent detection and JSON structure.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Few-Shot Examples (JSON)</label>
                        <textarea name="ai_examples_json" rows="6" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-indigo-500">{{ $config['ai_examples_json'] }}</textarea>
                        <p class="mt-1 text-xs text-gray-500">Examples of input/output to guide the model.</p>
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition shadow-lg">
                        Save AI Configuration
                    </button>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Local AI Tester</h2>
                <livewire:admin.ai-tester />
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Usage & Cost</h2>
                <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg border border-gray-200 dark:border-gray-700">
                    <p class="text-xs text-gray-500 uppercase font-medium">Estimated monthly cost</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white">$0.00</p>
                </div>
                <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg border border-gray-200 dark:border-gray-700">
                    <p class="text-xs text-gray-500 uppercase font-medium">Requests (Last 24h)</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-white">0</p>
                </div>
                <p class="text-[10px] text-gray-400 italic">Cost tracking is a placeholder for future multi-agent instrumentation.</p>
            </div>
        </div>
    </div>
</x-admin-layout>
