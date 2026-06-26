@php
    $scenarioLabels = [
        'supervision_content' => 'Supervision de contenu',
        'clarify_help_request' => 'Clarification de demande d\'aide',
        'blog_generate' => 'Blog — Génération d\'article',
        'blog_correct' => 'Blog — Correction d\'article',
        'profile_agent_master' => 'Agent de profil IA — Prompt master',
    ];
@endphp

<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Prompts IA
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
                <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        Liste des prompts
                    </h3>
                    <a href="{{ route('admin.ai-prompts.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Nouveau prompt
                    </a>
                </div>

                <div class="p-4 sm:p-6">
                    <form method="GET" class="mb-6 flex flex-col sm:flex-row gap-3">
                        <div class="flex-1">
                            <input type="text" name="search" value="{{ $search }}"
                                   placeholder="Rechercher par nom..."
                                   class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <select name="scenario_id"
                                    class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    onchange="this.form.submit()">
                                <option value="">Tous les scénarios</option>
                                <option value="supervision_content" @selected($scenarioId === 'supervision_content')>Supervision de contenu</option>
                                <option value="clarify_help_request" @selected($scenarioId === 'clarify_help_request')>Clarification de demande d'aide</option>
                                <option value="blog_generate" @selected($scenarioId === 'blog_generate')>Blog — Génération d'article</option>
                                <option value="blog_correct" @selected($scenarioId === 'blog_correct')>Blog — Correction d'article</option>
                                <option value="profile_agent_master" @selected($scenarioId === 'profile_agent_master')>Agent de profil IA — Prompt master</option>
                            </select>
                        </div>
                        <button type="submit"
                                class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600">
                            Rechercher
                        </button>
                        @if($search || $scenarioId)
                            <a href="{{ route('admin.ai-prompts') }}"
                               class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                                Effacer
                            </a>
                        @endif
                    </form>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Nom
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Scénario
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Version
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Actif
                                    </th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($prompts as $prompt)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ $prompt->name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ $scenarioLabels[$prompt->scenario_id] ?? $prompt->scenario_id }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            v{{ $prompt->version }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if($prompt->is_active)
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">
                                                    Actif
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300">
                                                    Inactif
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="{{ route('admin.ai-prompts.show', $prompt) }}"
                                               class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300 mr-3">
                                                Voir
                                            </a>
                                            <a href="{{ route('admin.ai-prompts.edit', $prompt) }}"
                                               class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300 mr-3">
                                                Modifier
                                            </a>
                                            <form method="POST" action="{{ route('admin.ai-prompts.destroy', $prompt) }}"
                                                  class="inline-block"
                                                  onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce prompt ?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">
                                                    Supprimer
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                            Aucun prompt trouvé.
                                            <a href="{{ route('admin.ai-prompts.create') }}"
                                               class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                                Créer le premier
                                            </a>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $prompts->links() }}
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
