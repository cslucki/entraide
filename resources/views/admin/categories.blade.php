<x-admin-layout title="Catégories">
    <div class="grid lg:grid-cols-3 gap-6">
        <!-- Category list -->
        <div class="lg:col-span-2 space-y-4">
            @forelse($categories as $cat)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <span class="w-4 h-4 rounded-full flex-shrink-0" style="background-color:{{ $cat->color }}"></span>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $cat->name }}</p>
                            <p class="text-xs text-gray-500">{{ $cat->services_count }} services · {{ $cat->service_requests_count }} demandes · {{ $cat->skills_count }} compétences</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2" x-data="{ editing: false }">
                        <button @click="editing = !editing" class="text-xs text-indigo-600 hover:underline">Modifier</button>
                        @if($cat->services_count === 0 && $cat->service_requests_count === 0)
                        <form method="POST" action="{{ route('admin.categories.destroy', $cat) }}"
                              onsubmit="return confirm('Supprimer cette catégorie et ses compétences ?')">
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-500 hover:underline">Supprimer</button>
                        </form>
                        @endif

                        <!-- Edit form -->
                        <div x-show="editing" x-cloak class="absolute right-0 mt-2 w-72 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg z-10 p-4" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%);">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Modifier la catégorie</p>
                            <form method="POST" action="{{ route('admin.categories.update', $cat) }}" class="space-y-2">
                                @csrf @method('PATCH')
                                <input type="text" name="name" value="{{ $cat->name }}" required
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                                <div class="flex items-center gap-2">
                                    <input type="color" name="color" value="{{ $cat->color }}"
                                        class="w-10 h-10 rounded cursor-pointer border border-gray-300 dark:border-gray-600">
                                    <span class="text-xs text-gray-500">Couleur</span>
                                </div>
                                <div class="flex gap-2 pt-1">
                                    <button type="submit" class="flex-1 px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">Enregistrer</button>
                                    <button type="button" @click="editing = false" class="px-3 py-1.5 border border-gray-300 dark:border-gray-600 text-sm rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">Annuler</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Skills list -->
                <div class="px-5 py-3">
                    <div class="flex flex-wrap gap-2 mb-3">
                        @foreach($cat->skills as $skill)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded text-xs">
                            {{ $skill->name }}
                            <form method="POST" action="{{ route('admin.skills.destroy', $skill) }}" class="inline"
                                  onsubmit="return confirm('Supprimer cette compétence ?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="ml-0.5 text-indigo-400 hover:text-red-500 leading-none">&times;</button>
                            </form>
                        </span>
                        @endforeach
                        @if($cat->skills->isEmpty())
                        <span class="text-xs text-gray-400">Aucune compétence.</span>
                        @endif
                    </div>

                    <!-- Add skill -->
                    <form method="POST" action="{{ route('admin.categories.skills.store', $cat) }}" class="flex gap-2">
                        @csrf
                        <input type="text" name="name" placeholder="Nouvelle compétence..." required
                            class="flex-1 px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-xs focus:ring-2 focus:ring-indigo-500">
                        <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white text-xs rounded-lg hover:bg-indigo-700">+</button>
                    </form>
                </div>
            </div>
            @empty
            <p class="text-sm text-gray-400">Aucune catégorie.</p>
            @endforelse
        </div>

        <!-- Add category form -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 sticky top-6">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Nouvelle catégorie</h2>
                <form method="POST" action="{{ route('admin.categories.store') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nom</label>
                        <input type="text" name="name" required placeholder="Ex: Photographie"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Couleur</label>
                        <div class="flex items-center gap-3">
                            <input type="color" name="color" value="#6366f1"
                                class="w-12 h-10 rounded cursor-pointer border border-gray-300 dark:border-gray-600">
                            <span class="text-xs text-gray-500">Choisir une couleur de badge</span>
                        </div>
                    </div>
                    <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 font-medium">
                        Créer la catégorie
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-admin-layout>
