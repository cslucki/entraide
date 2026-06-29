<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Modifier : {{ $systemEmailTemplate->name }}
            </h2>
            <a href="{{ request('redirect', route('admin.system-email-templates')) }}"
               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                ← Retour à la liste
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <div class="mb-4 flex gap-4 text-sm text-gray-500 dark:text-gray-400">
                        @if($systemEmailTemplate->organization)
                            <span>Organisation : <strong>{{ $systemEmailTemplate->organization->name }}</strong></span>
                        @else
                            <span class="italic">Globale</span>
                        @endif
                        @if($systemEmailTemplate->locale)
                            <span>Locale : <strong>{{ strtoupper($systemEmailTemplate->locale) }}</strong></span>
                        @endif
                        <span>Slug : <strong class="font-mono">{{ $systemEmailTemplate->slug }}</strong></span>
                    </div>

                    <form method="POST" action="{{ route('admin.system-email-templates.update', $systemEmailTemplate) }}" class="space-y-6">
                        @csrf
                        @method('PUT')

                        <input type="hidden" name="redirect" value="{{ request('redirect', route('admin.system-email-templates')) }}">

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Slug</label>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $systemEmailTemplate->slug }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom</label>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $systemEmailTemplate->name }}</p>
                            </div>
                        </div>

                        <div>
                            <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Sujet *
                            </label>
                            <input type="text" name="subject" id="subject" required
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('subject') border-red-500 @enderror"
                                   value="{{ old('subject', $systemEmailTemplate->subject) }}">
                            @error('subject')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                @php $varDisplay = collect($systemEmailTemplate->variables ?? [])->map(fn($v) => '{{ '.$v.' }}')->implode(' '); @endphp
                                Variables disponibles : <code class="text-indigo-600 dark:text-indigo-400">{{ $varDisplay }}</code>
                            </p>
                        </div>

                        <div>
                            <label for="content_html" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Contenu HTML *
                            </label>
                            <textarea name="content_html" id="content_html" rows="15" required
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm @error('content_html') border-red-500 @enderror">{{ old('content_html', $systemEmailTemplate->content_html) }}</textarea>
                            @error('content_html')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Utilisez <code class="text-indigo-600 dark:text-indigo-400">@{{ variable_name }}</code> pour les variables.
                                Le HTML brut est injecté dans le layout email.
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="enabled" id="enabled" value="1"
                                   class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                   @checked(old('enabled', $systemEmailTemplate->enabled))>
                            <label for="enabled" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Template actif
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 ml-6">
                            Si désactivé, la notification utilisera la vue Blade par défaut.
                        </p>

                        <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                            <a href="{{ request('redirect', route('admin.system-email-templates')) }}"
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
