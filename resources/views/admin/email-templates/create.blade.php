<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Créer un template d'email
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.email-templates.store') }}" class="space-y-6">
                        @csrf

                        {{-- TASK-1694 bug W — la documentation des variables, posee AVANT
                             les champs qui s'en servent : le sujet et le contenu.
                             Un bloc unique, lu dans `EmailerService::ALLOWED_VARS` — voir
                             sa documentation pour pourquoi il n'y a pas deux listes. --}}
                        @include('admin.email-templates.partials.variables-doc')

                        {{-- TASK-1694 bug AA — les cibles, en cases a cocher.
                             Demande de Cyril : « permettre de creer un email type
                             pour plusieurs organisations d'un coup ». Une ligne est
                             creee par case cochee ; ce qui existe deja est saute et
                             annonce, jamais ecrase. --}}
                        <div>
                            <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('admin.emailer_scope_label') }} *
                            </span>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('admin.emailer_scope_hint') }}
                            </p>

                            <div class="mt-2 space-y-1.5 rounded-lg border border-gray-200 dark:border-gray-700 p-3 max-h-60 overflow-y-auto"
                                 x-data="{
                                     toutes() {
                                         const cases = $el.querySelectorAll('input[name=\'organization_ids[]\']');
                                         const aCocher = [...cases].some(c => ! c.checked);
                                         cases.forEach(c => c.checked = aCocher);
                                     },
                                 }">
                                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                    <input type="checkbox" name="organization_ids[]"
                                           value="{{ \App\Http\Controllers\Admin\AdminEmailTemplatesController::CIBLE_GLOBALE }}"
                                           @checked(in_array(\App\Http\Controllers\Admin\AdminEmailTemplatesController::CIBLE_GLOBALE, (array) old('organization_ids', []), true))
                                           class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                                           data-emailer-scope-option="global">
                                    <span class="font-medium">{{ __('admin.emailer_scope_global') }}</span>
                                </label>

                                @foreach($organizations as $_org)
                                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                                        <input type="checkbox" name="organization_ids[]" value="{{ $_org->id }}"
                                               @checked(in_array($_org->id, (array) old('organization_ids', []), true))
                                               class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                                               data-emailer-scope-option="{{ $_org->slug }}">
                                        <span>{{ $_org->name }} <span class="text-xs text-gray-400 font-mono">{{ $_org->slug }}</span></span>
                                    </label>
                                @endforeach

                                <button type="button" @click="toutes()"
                                        class="mt-1 text-xs text-indigo-600 hover:underline"
                                        data-emailer-scope-toggle-all>
                                    {{ __('admin.select_all_toggle') }}
                                </button>
                            </div>

                            @error('organization_ids')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- TASK-1694 bug Z — la langue du gabarit.
                             « Non precisee » est une valeur, pas un oubli : c'est
                             l'etat des lignes anterieures au 24/10, et un gabarit
                             sans langue reste propose dans les DEUX langues au
                             moment de l'envoi. --}}
                        <div>
                            <label for="locale" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('admin.emailer_locale_label') }}
                            </label>
                            <select name="locale" id="locale"
                                    class="mt-1 block w-full max-w-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    data-emailer-locale>
                                <option value="" @selected(old('locale') === null || old('locale') === '')>{{ __('admin.emailer_locale_unset') }}</option>
                                @foreach(\App\Models\EmailTemplate::LOCALES as $_loc)
                                    <option value="{{ $_loc }}" @selected(old('locale') === $_loc)>{{ strtoupper($_loc) }}</option>
                                @endforeach
                            </select>
                            @error('locale')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="slug" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Slug *
                            </label>
                            <input type="text" name="slug" id="slug" required
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   value="{{ old('slug') }}">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Identifiant unique utilisé pour le code (ex: welcome, transaction_status)
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
                            <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Sujet de l'email *
                            </label>
                            <input type="text" name="subject" id="subject" required
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   value="{{ old('subject') }}">
                            {{-- TASK-1694 bug W — l'aide qui vivait ICI etait FAUSSE :
                                 elle annoncait les variables `user_name` et
                                 `app_name`, qui ne sont dans aucune liste. Mesure du
                                 24/10 : toutes deux ressortent VERBATIM de
                                 `interpolate()`, accolades comprises. La vraie
                                 documentation est desormais un bloc partage, lu dans
                                 `EmailerService::ALLOWED_VARS`. --}}
                        </div>

                        <div>
                            <label for="content_html" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Contenu HTML *
                            </label>
                            <textarea name="content_html" id="content_html" rows="15" required
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm">{{ old('content_html') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                HTML autorisé. Utilisez les variables entre accolades doubles.
                            </p>
                        </div>

                        <div>
                            <label for="variables" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Variables (optionnel, une par ligne)
                            </label>
                            <textarea name="variables" id="variables" rows="4"
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm">{{ old('variables') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Liste des variables utilisables dans ce template (pour documentation)
                            </p>
                        </div>

                        <div class="flex items-center justify-end gap-3">
                            <a href="{{ route('admin.email-templates') }}"
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                Annuler
                            </a>
                            <button type="submit"
                                    class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                Créer le template
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
