<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Créer un prompt IA
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.ai-prompts.store') }}" class="space-y-6">
                        @csrf

                        <div>
                            <label for="scenario_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Scénario *
                            </label>
                            <select name="scenario_id" id="scenario_id" required
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Sélectionner un scénario</option>
                                <option value="supervision_content" @selected(old('scenario_id') === 'supervision_content')>Supervision de contenu</option>
                                <option value="clarify_help_request" @selected(old('scenario_id') === 'clarify_help_request')>Clarification de demande d'aide</option>
                                <option value="blog_generate" @selected(old('scenario_id') === 'blog_generate')>Blog — Génération d'article</option>
                                <option value="blog_correct" @selected(old('scenario_id') === 'blog_correct')>Blog — Correction d'article</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                La version sera automatiquement incrémentée.
                            </p>
                        </div>

                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Nom *
                            </label>
                            <input type="text" name="name" id="name" required
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   value="{{ old('name') }}">
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Description
                            </label>
                            <textarea name="description" id="description" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description') }}</textarea>
                        </div>

                        <div>
                            <label for="prompt_text" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Contenu du prompt *
                            </label>
                            <textarea name="prompt_text" id="prompt_text" rows="15" required
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm">{{ old('prompt_text') }}</textarea>
                        </div>

                        <div>
                            <label for="metadata" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Métadonnées (JSON)
                            </label>
                            <textarea name="metadata" id="metadata" rows="4"
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm">{{ old('metadata') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Facultatif — JSON.
                            </p>
                        </div>

                        <div class="flex items-center justify-end gap-3">
                            <a href="{{ route('admin.ai-prompts') }}"
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                Annuler
                            </a>
                            <button type="submit"
                                    class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                Créer le prompt
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
