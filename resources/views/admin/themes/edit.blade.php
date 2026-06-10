<x-admin-layout title="Modifier le thème">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.themes.update', $theme) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Informations</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Clé <span class="text-red-500">*</span></label>
                    <input type="text" name="key" value="{{ old('key', $theme->key) }}" required maxlength="50"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono @error('key') border-red-500 @enderror">
                    @error('key')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Libellé <span class="text-red-500">*</span></label>
                    <input type="text" name="label" value="{{ old('label', $theme->label) }}" required maxlength="100"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('label') border-red-500 @enderror">
                    @error('label')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('description') border-red-500 @enderror">{{ old('description', $theme->description) }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Tokens — mode clair</h2>
                <div>
                    <textarea name="tokens" rows="10"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-900 text-green-400 font-mono text-sm @error('tokens') border-red-500 @enderror">{{ old('tokens', json_encode($theme->tokens ?? [], JSON_PRETTY_PRINT)) }}</textarea>
                    @error('tokens')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Tokens — mode sombre</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Optionnel. Si vide, le mode sombre utilisera des valeurs par défaut.</p>
                <div>
                    <textarea name="dark_tokens" rows="10"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-900 text-green-400 font-mono text-sm @error('dark_tokens') border-red-500 @enderror">{{ old('dark_tokens', json_encode($theme->dark_tokens ?? [], JSON_PRETTY_PRINT)) }}</textarea>
                    @error('dark_tokens')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="hidden" name="is_default" value="0">
                    <input type="checkbox" name="is_default" value="1"
                        {{ old('is_default', $theme->is_default) ? 'checked' : '' }}
                        class="w-4 h-4 text-amber-600 rounded border-gray-300 focus:ring-amber-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Thème par défaut</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Sera appliqué aux organisations sans thème spécifique.</p>
                    </div>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    Enregistrer les modifications
                </button>
                <a href="{{ route('admin.themes') }}" class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
