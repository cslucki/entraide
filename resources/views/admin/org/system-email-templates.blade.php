<x-org-admin-layout :title="__('admin.emailer_system')" :organization="$organization">
    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
        <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('admin.system_email_desc') }}
            </p>
            {{-- TASK-1694 bug AB — dire POURQUOI certains gabarits ne s'editent pas.
                 Un bouton « Adapter » la ou on attend « Modifier » est une surprise
                 si rien ne l'explique. --}}
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" data-system-email-adapt-hint>
                {{ __('admin.system_email_adapt_hint') }}
            </p>
        </div>

        <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700">
            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.locale') }}</label>
                    <select name="locale" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 text-sm">
                        <option value="">{{ __('admin.all_locales') }}</option>
                        <option value="fr" @selected($currentLocale === 'fr')>FR</option>
                        <option value="en" @selected($currentLocale === 'en')>EN</option>
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                    {{ __('admin.filter') }}
                </button>
                @if($currentLocale !== $organization->locale)
                    <a href="{{ route('organization.admin.system-email-templates', $organization) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm rounded-md hover:bg-gray-50 dark:hover:bg-gray-700">
                        {{ __('admin.clear') }}
                    </a>
                @endif
            </form>
        </div>

        <div class="p-4 sm:p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.locale') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.common_name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.slug') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.common_subject') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.system_email_source') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.status') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.outils.action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        {{-- TASK-1694 bug AB — une ligne par (slug, langue), et la SOURCE est dite.
                             L'ecran repond a « quel email part de chez moi, et d'ou
                             vient-il ? », pas a « quelles lignes existent en base ».
                             Un gabarit non adapte s'affiche dans sa version Plateforme,
                             avec « Adapter » — c'est la sortie de l'impasse signalee par
                             Cyril : liste vide et aucun bouton. --}}
                        @forelse($templates as $_entree)
                            @php($template = $_entree['template'])
                            @php($_adapte = $_entree['adapte'])
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" data-system-email-row="{{ $template->slug }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">
                                    {{ strtoupper($template->locale ?? '-') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ $template->name }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">
                                    {{ $template->slug }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300 max-w-xs truncate">
                                    {{ $template->subject }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if($_adapte)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200"
                                              data-system-email-source="organization">
                                            {{ __('admin.system_email_source_org') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300"
                                              data-system-email-source="platform">
                                            {{ __('admin.system_email_source_platform') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if($template->enabled)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                            {{ __('admin.active') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                            {{ __('admin.inactive') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    @if($_adapte)
                                        <a href="{{ route('organization.admin.system-email-templates.edit', [$organization, $template]) }}"
                                           class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300"
                                           data-system-email-edit="{{ $template->id }}">
                                            {{ __('admin.edit') }}
                                        </a>
                                    @else
                                        <form method="POST" action="{{ route('organization.admin.system-email-templates.adapt', [$organization, $template]) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300"
                                                    data-system-email-adapt="{{ $template->id }}">
                                                {{ __('admin.system_email_adapt') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('admin.no_system_templates') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-org-admin-layout>
