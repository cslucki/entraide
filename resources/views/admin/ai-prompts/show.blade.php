<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Prompt : {{ $prompt->name }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            Détails du prompt
                        </h3>
                        <a href="{{ route('admin.ai-prompts.edit', $prompt) }}"
                           class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                            Modifier
                        </a>
                    </div>

                    <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Nom</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                {{ $prompt->name }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Scénario</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 font-mono">
                                {{ $prompt->scenario_id }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Version</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                v{{ $prompt->version }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Statut</dt>
                            <dd class="mt-1">
                                @if($prompt->is_active)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">
                                        Actif
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300">
                                        Inactif
                                    </span>
                                @endif
                            </dd>
                        </div>
                        @if($prompt->description)
                            <div class="sm:col-span-2">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Description</dt>
                                <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                    {{ $prompt->description }}
                                </dd>
                            </div>
                        @endif
                        @if($prompt->metadata)
                            <div class="sm:col-span-2">
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Métadonnées</dt>
                                <dd class="mt-1">
                                    <pre class="bg-gray-50 dark:bg-gray-900 rounded-md p-3 text-xs text-gray-700 dark:text-gray-300 font-mono whitespace-pre-wrap overflow-x-auto">{{ json_encode($prompt->metadata, JSON_PRETTY_PRINT) }}</pre>
                                </dd>
                            </div>
                        @endif
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Contenu du prompt</dt>
                            <dd class="mt-1">
                                <pre class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg text-sm font-mono whitespace-pre-wrap overflow-x-auto">{{ $prompt->prompt_text }}</pre>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <a href="{{ route('admin.ai-prompts') }}"
                   class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                    ← Retour à la liste
                </a>
                <form method="POST" action="{{ route('admin.ai-prompts.destroy', $prompt) }}"
                      onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce prompt ?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                        Supprimer le prompt
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-admin-layout>
