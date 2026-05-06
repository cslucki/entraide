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
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Variables disponibles : {{ '{{user_name}}', '{{app_name}}' }}
                            </p>
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
