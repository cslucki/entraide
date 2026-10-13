<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('admin.emailer_system') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
                <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('admin.system_email_desc') }}
                    </p>
                </div>

                <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700">
                    <form method="GET" class="flex flex-wrap gap-4 items-end">
                        {{-- TASK-1659 : un filtre par slug arrive par l'URL (lien
                             « Gérer le modèle d'email »). Il doit survivre a une
                             soumission du formulaire, sinon filtrer par langue
                             ramenerait toute la liste. --}}
                        @if(request('slug'))
                            <input type="hidden" name="slug" value="{{ request('slug') }}">
                        @endif
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.organization') }}</label>
                            <select name="organization_id" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 text-sm">
                                <option value="">{{ __('admin.all_organizations') }}</option>
                                @foreach($organizations as $org)
                                    <option value="{{ $org->id }}" @selected(request('organization_id') == $org->id)>{{ $org->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.locale') }}</label>
                            <select name="locale" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 text-sm">
                                <option value="">{{ __('admin.all_locales') }}</option>
                                <option value="fr" @selected(request('locale') === 'fr')>FR</option>
                                <option value="en" @selected(request('locale') === 'en')>EN</option>
                            </select>
                        </div>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                            {{ __('admin.filter') }}
                        </button>
                        @if(request('organization_id') || request('locale'))
                            <a href="{{ route('admin.system-email-templates', array_filter(['slug' => request('slug')])) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm rounded-md hover:bg-gray-50 dark:hover:bg-gray-700">
                                {{ __('admin.clear') }}
                            </a>
                        @endif
                    </form>
                </div>

                {{-- TASK-1681 — le MODELE SYSTEME PAR DEFAUT, visible meme sans
                     surcharge DB : c'est lui que le mailer utilise (repli Blade).
                     La carte offre la creation d'UNE personnalisation, creee
                     DESACTIVEE (l'admin relit puis active — jamais un HTML brut
                     qui prendrait le pas d'office, regression du 01/10). --}}
                @if($defaultTemplate)
                <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700">
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $defaultTemplate['name'] }}</span>
                            <span class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">{{ __('admin.system_email_default_badge') }}</span>
                            <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                {{ $defaultTemplate['has_enabled_override'] ? __('admin.system_email_default_in_use_partial') : __('admin.system_email_default_in_use') }}
                            </span>
                        </div>
                        <form method="POST" action="{{ route('admin.system-email-templates.store') }}" class="mt-3 flex flex-wrap items-end gap-3">
                            @csrf
                            <input type="hidden" name="slug" value="{{ $defaultTemplate['slug'] }}">
                            <div>
                                <label for="customize_organization_id" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.organization') }}</label>
                                <select name="organization_id" id="customize_organization_id" required
                                        class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 text-sm">
                                    @foreach($organizations as $org)
                                        <option value="{{ $org->id }}" @selected(request('organization_id') == $org->id)>{{ $org->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="customize_locale" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.locale') }}</label>
                                <select name="locale" id="customize_locale" required
                                        class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 text-sm">
                                    <option value="fr">FR</option>
                                    <option value="en">EN</option>
                                </select>
                            </div>
                            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">
                                {{ __('admin.system_email_create_customization') }}
                            </button>
                        </form>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.system_email_create_customization_hint') }}</p>
                    </div>
                </div>
                @endif

                <div class="p-4 sm:p-6">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.organization') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.locale') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.common_name') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.slug') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.common_subject') }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('admin.status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($templates as $template)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                            @if($template->organization)
                                                <a href="{{ route('admin.organizations.edit', $template->organization) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                                    {{ $template->organization->name }}
                                                </a>
                                            @else
                                                <span class="text-gray-400 italic">{{ __('admin.global') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">
                                            {{ strtoupper($template->locale ?? '-') }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            {{-- TASK-1681 — le NOM est le lien d'edition : la
                                                 colonne Action vivait hors ecran a droite, et
                                                 la ligne semblait inerte. --}}
                                            <a href="{{ route('admin.system-email-templates.edit', ['systemEmailTemplate' => $template, 'redirect' => request()->fullUrl()]) }}"
                                               class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                                {{ $template->name }}
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">
                                            {{ $template->slug }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300 max-w-xs truncate">
                                            {{ $template->subject }}
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
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                            {{-- TASK-1681 — arrive filtre par slug (lien de
                                                 bulk-create), une liste vide est l'etat NORMAL :
                                                 la surcharge est facultative, l'email part avec
                                                 le modele par defaut. Le dire, plutot qu'une
                                                 page vide inquietante. --}}
                                            @if(request('slug'))
                                                {{ __('admin.system_email_no_override') }}
                                            @else
                                                {{ __('admin.no_system_templates') }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
