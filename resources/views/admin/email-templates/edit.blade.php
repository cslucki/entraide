<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Modifier le template : {{ $emailTemplate->name }}
            </h2>
            <a href="{{ route('admin.email-templates') }}"
               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                ← Retour à la liste
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <form method="POST" action="{{ route('admin.email-templates.update', $emailTemplate) }}" class="space-y-6">
                        @csrf
                        @method('PUT')

                        {{-- TASK-1694 bug W — la documentation des variables, posee AVANT
                             les champs qui s'en servent : le sujet et le contenu.
                             Un bloc unique, lu dans `EmailerService::ALLOWED_VARS` — voir
                             sa documentation pour pourquoi il n'y a pas deux listes. --}}
                        @include('admin.email-templates.partials.variables-doc')

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
                                <option value="" @selected(old('locale', $emailTemplate->locale) === null || old('locale', $emailTemplate->locale) === '')>{{ __('admin.emailer_locale_unset') }}</option>
                                @foreach(\App\Models\EmailTemplate::LOCALES as $_loc)
                                    <option value="{{ $_loc }}" @selected(old('locale', $emailTemplate->locale) === $_loc)>{{ strtoupper($_loc) }}</option>
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
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('slug') border-red-500 @enderror"
                                   value="{{ old('slug', $emailTemplate->slug) }}">
                            @error('slug')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @else
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Identifiant unique utilisé pour le code</p>
                            @enderror
                        </div>

                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Nom *
                            </label>
                            <input type="text" name="name" id="name" required
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('name') border-red-500 @enderror"
                                   value="{{ old('name', $emailTemplate->name) }}">
                            @error('name')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Sujet de l'email *
                            </label>
                            <input type="text" name="subject" id="subject" required
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('subject') border-red-500 @enderror"
                                   value="{{ old('subject', $emailTemplate->subject) }}">
                            @error('subject')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="content_html" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Contenu HTML *
                            </label>
                            <textarea name="content_html" id="content_html" rows="15" required
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm @error('content_html') border-red-500 @enderror">{{ old('content_html', $emailTemplate->content_html) }}</textarea>
                            @error('content_html')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="variables" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Variables (une par ligne)
                            </label>
                            <textarea name="variables" id="variables" rows="4"
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm">{{ old('variables', is_array($emailTemplate->variables) ? implode("\n", $emailTemplate->variables) : $emailTemplate->variables) }}</textarea>
                            @error('variables')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-between">
                            <a href="{{ route('admin.email-templates') }}"
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
