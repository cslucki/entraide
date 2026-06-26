<x-org-admin-layout title="{{ __('themes.edit_theme', ['label' => $theme->label]) }}" :organization="$organization">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('organization.admin.themes.update', [$organization, $theme]) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">{{ __('themes.info') }}</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('themes.key') }} <span class="text-red-500">*</span></label>
                    <input type="text" name="key" value="{{ old('key', $theme->key) }}" required maxlength="50"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono @error('key') border-red-500 @enderror">
                    @error('key')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('themes.label') }} <span class="text-red-500">*</span></label>
                    <input type="text" name="label" value="{{ old('label', $theme->label) }}" required maxlength="100"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('label') border-red-500 @enderror">
                    @error('label')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('themes.description') }}</label>
                    <textarea name="description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('description') border-red-500 @enderror">{{ old('description', $theme->description) }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">{{ __('themes.tokens_light') }}</h2>
                <div>
                    <textarea name="tokens" rows="10"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-900 text-green-400 font-mono text-sm @error('tokens') border-red-500 @enderror">{{ old('tokens', json_encode($theme->tokens ?? [], JSON_PRETTY_PRINT)) }}</textarea>
                    @error('tokens')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">{{ __('themes.tokens_dark') }}</h2>
                <div>
                    <textarea name="dark_tokens" rows="10"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-900 text-green-400 font-mono text-sm @error('dark_tokens') border-red-500 @enderror">{{ old('dark_tokens', json_encode($theme->dark_tokens ?? [], JSON_PRETTY_PRINT)) }}</textarea>
                    @error('dark_tokens')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    {{ __('themes.save') }}
                </button>
                <a href="{{ route('organization.admin.themes', $organization) }}" class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    {{ __('themes.cancel') }}
                </a>
            </div>
        </form>
    </div>
</x-org-admin-layout>
