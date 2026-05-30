<x-admin-layout title="Meta-Organisation">

    <div class="max-w-2xl">
        <div class="mb-6">
            <h1 class="text-xl font-bold text-white">Meta-Organisation</h1>
            <p class="text-sm text-gray-400 mt-1">Paramètres globaux du site BouclePro (hors organisations spécifiques).</p>
        </div>

        <form method="POST" action="{{ route('admin.meta-organization.update') }}" class="space-y-6">
            @csrf
            @method('POST')

            <!-- Apparence -->
            <div class="bg-gray-800 rounded-xl border border-gray-700 p-6">
                <h2 class="text-sm font-semibold text-gray-300 uppercase tracking-wider mb-5">Apparence</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-3">Mode couleur du site global</label>
                    <div class="grid grid-cols-2 gap-4">

                        <label class="relative cursor-pointer">
                            <input type="radio" name="global_color_mode" value="dark"
                                   class="sr-only peer"
                                   {{ $settings['global_color_mode'] === 'dark' ? 'checked' : '' }}>
                            <div class="flex flex-col items-center gap-3 p-4 rounded-xl border-2 border-gray-600 peer-checked:border-indigo-500 peer-checked:bg-indigo-950/30 transition">
                                <!-- Dark preview -->
                                <div class="w-full h-16 rounded-lg bg-gray-900 border border-gray-700 overflow-hidden flex flex-col">
                                    <div class="h-3 bg-gray-800 border-b border-gray-700 flex items-center px-2 gap-1">
                                        <div class="w-8 h-1.5 bg-indigo-500 rounded-full"></div>
                                    </div>
                                    <div class="flex-1 p-1.5 flex flex-col gap-1">
                                        <div class="h-1.5 bg-gray-700 rounded w-3/4"></div>
                                        <div class="h-1.5 bg-gray-700 rounded w-1/2"></div>
                                    </div>
                                </div>
                                <div class="text-center">
                                    <p class="text-sm font-medium text-gray-200">Dark</p>
                                    <p class="text-xs text-gray-500">Fond sombre (recommandé)</p>
                                </div>
                                <div class="w-4 h-4 rounded-full border-2 border-gray-500 peer-checked:border-indigo-500 flex items-center justify-center">
                                    <div class="w-2 h-2 rounded-full {{ $settings['global_color_mode'] === 'dark' ? 'bg-indigo-500' : '' }}"></div>
                                </div>
                            </div>
                        </label>

                        <label class="relative cursor-pointer">
                            <input type="radio" name="global_color_mode" value="light"
                                   class="sr-only peer"
                                   {{ $settings['global_color_mode'] === 'light' ? 'checked' : '' }}>
                            <div class="flex flex-col items-center gap-3 p-4 rounded-xl border-2 border-gray-600 peer-checked:border-indigo-500 peer-checked:bg-indigo-950/30 transition">
                                <!-- Light preview -->
                                <div class="w-full h-16 rounded-lg bg-white border border-gray-200 overflow-hidden flex flex-col">
                                    <div class="h-3 bg-gray-50 border-b border-gray-200 flex items-center px-2 gap-1">
                                        <div class="w-8 h-1.5 bg-indigo-500 rounded-full"></div>
                                    </div>
                                    <div class="flex-1 p-1.5 flex flex-col gap-1">
                                        <div class="h-1.5 bg-gray-200 rounded w-3/4"></div>
                                        <div class="h-1.5 bg-gray-200 rounded w-1/2"></div>
                                    </div>
                                </div>
                                <div class="text-center">
                                    <p class="text-sm font-medium text-gray-200">Light</p>
                                    <p class="text-xs text-gray-500">Fond clair</p>
                                </div>
                                <div class="w-4 h-4 rounded-full border-2 border-gray-500 peer-checked:border-indigo-500 flex items-center justify-center">
                                    <div class="w-2 h-2 rounded-full {{ $settings['global_color_mode'] === 'light' ? 'bg-indigo-500' : '' }}"></div>
                                </div>
                            </div>
                        </label>

                    </div>
                    <p class="mt-3 text-xs text-gray-500">
                        Ce réglage s'applique uniquement au site global. Les organisations conservent leur propre personnalisation.
                    </p>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>

</x-admin-layout>
