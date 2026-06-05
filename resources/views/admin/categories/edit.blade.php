<x-admin-layout title="Modifier la catégorie">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.categories.update', $category) }}" class="space-y-6">
            @csrf @method('PUT')

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Noms de la catégorie</h2>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nom B2C (membres)</label>
                    <input type="text" name="name_b2c" value="{{ old('name_b2c', $category->name_b2c) }}" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    <p class="text-xs text-gray-500 mt-1">Nom visible par les membres (particuliers).</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nom B2B (entreprises)</label>
                    <input type="text" name="name_b2b" value="{{ old('name_b2b', $category->name_b2b) }}" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    <p class="text-xs text-gray-500 mt-1">Nom visible par les entreprises (selon la préférence de nommage).</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Couleur</label>
                    <div class="flex items-center gap-3">
                        <input type="color" name="color" value="{{ old('color', $category->color) }}"
                            class="w-12 h-10 rounded cursor-pointer border border-gray-300 dark:border-gray-600">
                        <span class="text-xs text-gray-500">Choisir une couleur de badge</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Services associés</h2>
                <p class="text-xs text-gray-500">Exemples de services typiques dans cette catégorie.</p>

                @for($i = 1; $i <= 5; $i++)
                @php $field = 'service_' . $i; @endphp
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Service {{ $i }}</label>
                    <input type="text" name="service_{{ $i }}" value="{{ old($field, $category->$field) }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                @endfor
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 font-medium">
                    Enregistrer
                </button>
                <a href="{{ route('admin.categories') }}" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
