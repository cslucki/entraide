<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Modifier le prompt : {{ $prompt->name }}
            </h2>
            <a href="{{ route('admin.ai-prompts') }}"
               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                ← Retour à la liste
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.ai-prompts.update', $prompt) }}" class="space-y-6">
                        @csrf
                        @method('PUT')

                        <div>
                            <label for="scenario_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Scénario
                            </label>
                             <input type="text" id="scenario_id"
                                    value="{{ $scenarioLabels[$prompt->scenario_id] ?? $prompt->scenario_id }}"
                                   disabled readonly
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 shadow-sm bg-gray-50 cursor-not-allowed">
                            <input type="hidden" name="scenario_id" value="{{ $prompt->scenario_id }}">
                        </div>

                        <div>
                            <label for="version" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Version
                            </label>
                            <input type="text" id="version"
                                   value="v{{ $prompt->version }}"
                                   disabled readonly
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 shadow-sm bg-gray-50 cursor-not-allowed">
                        </div>

                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Nom *
                            </label>
                            <input type="text" name="name" id="name" required
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('name') border-red-500 @enderror"
                                   value="{{ old('name', $prompt->name) }}">
                            @error('name')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Description
                            </label>
                            <textarea name="description" id="description" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('description') border-red-500 @enderror">{{ old('description', $prompt->description) }}</textarea>
                            @error('description')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="prompt_text" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Contenu du prompt *
                            </label>
                            <textarea name="prompt_text" id="prompt_text" rows="15" required
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm @error('prompt_text') border-red-500 @enderror">{{ old('prompt_text', $prompt->prompt_text) }}</textarea>
                            @error('prompt_text')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="is_active" id="is_active" value="1"
                                       class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                       @checked(old('is_active', $prompt->is_active))>
                                <label for="is_active" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Actif
                                </label>
                            </div>
                        </div>

                        <div>
                            <label for="metadata" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Métadonnées (JSON)
                            </label>
                            <textarea name="metadata" id="metadata" rows="4"
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm">{{ old('metadata', is_array($prompt->metadata) ? json_encode($prompt->metadata, JSON_PRETTY_PRINT) : $prompt->metadata) }}</textarea>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Facultatif — JSON.
                            </p>
                        </div>

                        <div class="flex items-center justify-between">
                            <a href="{{ route('admin.ai-prompts') }}"
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                ← Retour à la liste
                            </a>
                            <button type="submit"
                                    class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                Mettre à jour
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
