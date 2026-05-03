<x-admin-layout title="Configuration">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Identité de la plateforme</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom de la plateforme</label>
                    <input type="text" name="platform_name" value="{{ old('platform_name', $settings['platform_name']) }}"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500"
                        required maxlength="100">
                    @error('platform_name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tagline</label>
                    <input type="text" name="platform_tagline" value="{{ old('platform_tagline', $settings['platform_tagline']) }}"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500"
                        maxlength="255">
                    @error('platform_tagline')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Maintenance</h2>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="hidden" name="maintenance_mode" value="0">
                    <input type="checkbox" name="maintenance_mode" value="1"
                        {{ $settings['maintenance_mode'] === '1' ? 'checked' : '' }}
                        class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700 dark:text-gray-300">Activer le mode maintenance</span>
                </label>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Quand activé, seuls les admins peuvent accéder à la plateforme.</p>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                    class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    Sauvegarder
                </button>
            </div>
        </form>
    </div>
</x-admin-layout>
