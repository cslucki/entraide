<x-admin-layout title="AI Orchestration">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        {{-- Left Column: Configuration --}}
        <div class="lg:col-span-8 space-y-8">
            <form method="POST" action="{{ route('admin.ai.update') }}" class="space-y-8">
                @csrf

                {{-- Core Config Card --}}
                <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl rounded-[2rem] border border-gray-200 dark:border-gray-700/50 p-8 shadow-sm">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">Core Configuration</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Global AI behavior and provider settings</p>
                        </div>
                        <div class="flex items-center gap-6">
                            <label class="flex items-center gap-3 cursor-pointer group">
                                <div class="relative">
                                    <input type="checkbox" name="ai_enabled" value="1" {{ $config['ai_enabled'] ? 'checked' : '' }} class="sr-only peer">
                                    <div class="w-10 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </div>
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white transition-colors">AI Enabled</span>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer group">
                                <div class="relative">
                                    <input type="checkbox" name="ai_debug_mode" value="1" {{ $config['ai_debug_mode'] ? 'checked' : '' }} class="sr-only peer">
                                    <div class="w-10 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500"></div>
                                </div>
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-white transition-colors">Debug Mode</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Active Provider</label>
                            <select name="ai_provider" class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700 rounded-2xl text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all">
                                <option value="fake" {{ $config['ai_provider'] === 'fake' ? 'selected' : '' }}>Fake Provider (Local Rules)</option>
                                <option value="openai" {{ $config['ai_provider'] === 'openai' ? 'selected' : '' }}>OpenAI GPT Engine</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Model Identifier</label>
                            <input type="text" name="ai_openai_model" value="{{ $config['ai_openai_model'] }}" class="w-full px-4 py-3 bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700 rounded-2xl text-sm focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all font-mono">
                        </div>
                    </div>

                    @if($config['ai_provider'] === 'fake')
                    <div class="mt-6 p-4 bg-amber-500/5 border border-amber-500/20 rounded-2xl flex gap-4 items-start animate-pulse">
                        <div class="p-2 bg-amber-500/10 rounded-xl text-amber-600 dark:text-amber-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Using simulated intelligence</p>
                            <p class="text-xs text-amber-700/70 dark:text-amber-400/60 leading-relaxed mt-0.5">Prompt changes below will not affect behavior until OpenAI provider is active.</p>
                        </div>
                    </div>
                    @endif
                </div>

                {{-- Prompts Card --}}
                <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl rounded-[2rem] border border-gray-200 dark:border-gray-700/50 p-8 shadow-sm space-y-8">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">Prompt Orchestration</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Define how the AI perceives and routes user intents</p>
                    </div>

                    <div class="space-y-6">
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Master System Persona</label>
                            <textarea name="ai_master_prompt" rows="3" class="w-full p-4 bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700 rounded-2xl text-sm font-mono focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all resize-none">{{ $config['ai_master_prompt'] }}</textarea>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Classification Rules</label>
                            <textarea name="ai_classification_prompt" rows="8" class="w-full p-4 bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700 rounded-2xl text-sm font-mono focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all resize-none">{{ $config['ai_classification_prompt'] }}</textarea>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Few-Shot Learning Examples (JSON)</label>
                            <textarea name="ai_examples_json" rows="6" class="w-full p-4 bg-gray-50 dark:bg-gray-900/50 border border-gray-200 dark:border-gray-700 rounded-2xl text-sm font-mono focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all resize-none">{{ $config['ai_examples_json'] }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="px-8 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-full transition-all shadow-xl shadow-indigo-500/20 hover:shadow-indigo-500/40 active:scale-95">
                        Save AI Configuration
                    </button>
                </div>
            </form>

            {{-- interaction Logs --}}
            <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl rounded-[2rem] border border-gray-200 dark:border-gray-700/50 p-8 shadow-sm">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white tracking-tight mb-6">Recent Interactions</h2>
                <div class="overflow-hidden">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">
                                <th class="pb-4">Timestamp</th>
                                <th class="pb-4">User</th>
                                <th class="pb-4">Input</th>
                                <th class="pb-4 text-center">Intent</th>
                                <th class="pb-4 text-right">Confidence</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            @foreach($recentLogs as $log)
                            <tr class="group hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                <td class="py-4 text-[11px] text-gray-500 dark:text-gray-400">{{ $log->created_at->format('H:i:s d/m') }}</td>
                                <td class="py-4 text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ $log->user?->name ?? 'Guest' }}
                                </td>
                                <td class="py-4 text-xs text-gray-600 dark:text-gray-400 max-w-xs truncate" title="{{ $log->user_input }}">{{ $log->user_input }}</td>
                                <td class="py-4 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800">
                                        {{ $log->detected_intent }}
                                    </span>
                                </td>
                                <td class="py-4 text-right text-xs font-mono {{ $log->confidence_score > 0.8 ? 'text-green-500' : 'text-amber-500' }}">
                                    {{ number_format($log->confidence_score * 100, 0) }}%
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Right Column: Tester & History --}}
        <div class="lg:col-span-4 space-y-8">
            {{-- Tester --}}
            <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl rounded-[2rem] border border-gray-200 dark:border-gray-700/50 p-8 shadow-sm">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white tracking-tight mb-6">Real-time Tester</h2>
                <livewire:admin.ai-tester />
            </div>

            {{-- Prompt Version History --}}
            <div class="bg-white/80 dark:bg-gray-800/50 backdrop-blur-xl rounded-[2rem] border border-gray-200 dark:border-gray-700/50 p-8 shadow-sm">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white tracking-tight mb-6">Version Control</h2>
                <div class="space-y-4">
                    @foreach($promptHistory as $prompt)
                    <div class="flex items-start gap-4 p-3 hover:bg-gray-50 dark:hover:bg-gray-700/30 rounded-2xl transition-colors border border-transparent hover:border-gray-100 dark:hover:border-gray-700">
                        <div class="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0">
                            <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400">v{{ $prompt->version }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-tighter">{{ $prompt->type }}</p>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5 truncate">{{ $prompt->created_at->diffForHumans() }} by {{ $prompt->creator?->name }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Cost Card --}}
            <div class="bg-indigo-600 rounded-[2rem] p-8 text-white shadow-xl shadow-indigo-600/20 relative overflow-hidden">
                <div class="relative z-10">
                    <p class="text-xs font-bold uppercase tracking-widest opacity-70">Estimated Cost</p>
                    <div class="flex items-baseline gap-1 mt-2">
                        <span class="text-4xl font-bold">$0.00</span>
                        <span class="text-sm opacity-60">/ month</span>
                    </div>
                    <div class="mt-6 h-1 w-full bg-white/20 rounded-full overflow-hidden">
                        <div class="h-full bg-white w-0"></div>
                    </div>
                    <p class="text-[10px] mt-4 opacity-50 italic">Telemetry active for future analysis.</p>
                </div>
                {{-- Decorative circles --}}
                <div class="absolute -right-4 -bottom-4 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
                <div class="absolute -left-4 -top-4 w-24 h-24 bg-indigo-400/20 rounded-full blur-2xl"></div>
            </div>
        </div>
    </div>
</x-admin-layout>
