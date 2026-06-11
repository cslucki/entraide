@php $title = 'Réglages IA'; @endphp

<x-admin-layout>
    <div class="max-w-3xl space-y-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Configuration IA</h2>

        {{-- État actuel --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 space-y-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">État actuel</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Provider par défaut</p>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        @if($defaultProvider && isset($providers[$defaultProvider]))
                            {{ $providers[$defaultProvider]['label'] }}
                        @elseif($defaultProvider)
                            {{ ucfirst($defaultProvider) }}
                        @else
                            <span class="text-amber-600 dark:text-amber-400">Aucun</span>
                        @endif
                    </p>
                </div>

                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Environnement</p>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ $isProduction ? 'Production' : 'Local / Développement' }}
                    </p>
                </div>

                @if($currentProviderConfig)
                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Modèle</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 font-mono">{{ $currentProviderConfig['model'] }}</p>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Base URL</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 font-mono truncate">{{ $currentProviderConfig['base_url'] }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Formulaire de configuration --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-4">Modifier la configuration</h3>

            <form method="POST" action="{{ route('admin.ai-config.update') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="default_provider" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Provider par défaut</label>
                    <select name="default_provider" id="default_provider"
                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        <option value="">— Héritage (premier disponible) —</option>
                        @foreach($providers as $key => $info)
                            <option value="{{ $key }}" @selected($defaultProvider === $key)>{{ $info['label'] }}</option>
                        @endforeach
                    </select>
                    @error('default_provider')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="default_model" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Modèle (optionnel)</label>
                    <input type="text" name="default_model" id="default_model" value="{{ old('default_model', $defaultModel) }}"
                           placeholder="ex: gpt-4o-mini, ministral-3:3b"
                           class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Laissez vide pour utiliser le modèle par défaut du provider.</p>
                    @error('default_model')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit"
                            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                        Enregistrer
                    </button>
                    <a href="{{ route('admin.ai-config') }}"
                       class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg transition">
                        Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        {{-- Providers disponibles --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-4">Providers disponibles</h3>

            @if(empty($providers))
                <div class="text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/50 rounded-lg px-4 py-3">
                    Aucun provider configuré. Vérifiez les variables d'environnement (AI_SUPERVISION_ENABLED, OLLAMA_ENABLED, OPENROUTER_ENABLED).
                </div>
            @else
                <div class="space-y-3">
                    @foreach($providers as $key => $info)
                        <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700/50 rounded-lg px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="w-2 h-2 rounded-full {{ $defaultProvider === $key ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $info['label'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 font-mono">Modèle : {{ $info['models'] ? implode(', ', array_values($info['models'])) : 'N/A' }}</p>
                                </div>
                            </div>
                            <span class="text-xs {{ $info['type'] === 'local' ? 'text-amber-600 dark:text-amber-400' : 'text-indigo-600 dark:text-indigo-400' }}">{{ $info['type'] === 'local' ? 'Local' : 'Cloud' }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Note --}}
        <div class="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/50 rounded-lg px-4 py-3">
            <p class="font-medium mb-1">Fonctionnement</p>
            <p>Les réglages ci-dessus s'appliquent à la clarification des demandes d'aide utilisateur (feature flag <code>AI_CLARIFY_ENABLED</code>).</p>
            <p class="mt-1">Le provider par défaut est utilisé par ordre de priorité : réglage DB → <code>OLLAMA_ENABLED</code> → <code>OPENROUTER_ENABLED</code> → <code>OPENAI_SUPERVISION_ENABLED</code>.</p>
        </div>
    </div>
</x-admin-layout>
