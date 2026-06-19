<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $override ? "Modifier l'override" : 'Nouvel override' }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <form method="POST"
                          action="{{ $override ? route('admin.translations.overrides.update', $override) : route('admin.translations.overrides.store') }}"
                          class="space-y-6">
                        @csrf
                        @if($override) @method('PUT') @endif

                        <div>
                            <label for="organization_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Organisation
                            </label>
                            <select name="organization_id" id="organization_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">— Global (toutes les organisations) —</option>
                                @foreach($organizations as $org)
                                    <option value="{{ $org->id }}" {{ old('organization_id', $override?->organization_id) == $org->id ? 'selected' : '' }}>
                                        {{ $org->name }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Laissez vide pour un override global. Sélectionnez une organisation pour un override spécifique.
                            </p>
                            @error('organization_id')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <label for="locale" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Locale *
                                </label>
                                <select name="locale" id="locale" required
                                        class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @foreach($locales as $locale)
                                        <option value="{{ $locale }}" {{ old('locale', $override?->locale) == $locale ? 'selected' : '' }}>
                                            {{ $locale === 'fr' ? 'Français' : 'English' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('locale')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="group" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Groupe *
                                </label>
                                <input list="groups-list" name="group" id="group" required
                                       value="{{ old('group', $override?->group) }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <datalist id="groups-list">
                                    @foreach($groups as $g)
                                        <option value="{{ $g }}">
                                    @endforeach
                                </datalist>
                                @error('group')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <label for="key" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Clé *
                            </label>
                            <input type="text" name="key" id="key" required maxlength="100"
                                   value="{{ old('key', $override?->key) }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Exemple : <code class="rounded bg-gray-100 px-1 py-0.5 font-mono dark:bg-gray-700">enter_main_loop</code>
                            </p>
                            @error('key')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="value" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Valeur *
                            </label>
                            <textarea name="value" id="value" rows="4" required
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm">{{ old('value', $override?->value) }}</textarea>
                            @error('value')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="is_active" id="is_active" value="1"
                                   {{ old('is_active', $override?->is_active ?? true) ? 'checked' : '' }}
                                   class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <label for="is_active" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Actif
                            </label>
                        </div>

                        <div class="flex items-center justify-end gap-3">
                            <a href="{{ route('admin.translations') }}"
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                Annuler
                            </a>
                            <button type="submit"
                                    class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition">
                                {{ $override ? 'Mettre à jour' : 'Créer l\'override' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
