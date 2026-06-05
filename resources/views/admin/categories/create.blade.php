<x-admin-layout title="Nouvelle catégorie">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.categories.store') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-2">
                <label for="organization_id" class="block text-xs font-medium text-gray-700 dark:text-gray-300">Organisation</label>
                <select id="organization_id" name="organization_id" required
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    @foreach($organizations as $org)
                    <option value="{{ $org->id }}" @selected(old('organization_id', $organization?->id) === $org->id)>
                        {{ $org->name }} ({{ $org->slug }})
                    </option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500">La catégorie et ses compétences seront rattachées à cette organisation.</p>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Noms de la catégorie</h2>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nom B2C (membres)</label>
                    <input type="text" name="name_b2c" value="{{ old('name_b2c') }}" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    <p class="text-xs text-gray-500 mt-1">Nom visible par les membres (particuliers).</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nom B2B (entreprises)</label>
                    <input type="text" name="name_b2b" value="{{ old('name_b2b') }}" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    <p class="text-xs text-gray-500 mt-1">Nom visible par les entreprises (selon la préférence de nommage).</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Couleur</label>
                    <div class="flex items-center gap-3">
                        <input type="color" name="color" value="{{ old('color', '#6366f1') }}"
                            class="w-12 h-10 rounded cursor-pointer border border-gray-300 dark:border-gray-600">
                        <span class="text-xs text-gray-500">Choisir une couleur de badge</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5" x-data="{
                skills: [],
                addSkill() { this.skills.push(''); },
                removeSkill(index) { this.skills.splice(index, 1); }
            }">
                <div>
                    <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Compétences liées</h2>
                    <p class="text-xs text-gray-500 mt-1">Compétences rattachées à cette catégorie pour cette organisation.</p>
                </div>

                <div class="space-y-2">
                    <template x-for="(skill, index) in skills" :key="index">
                        <div class="flex gap-2">
                            <input type="text" :name="'skills[' + index + ']'" x-model="skills[index]" placeholder="Compétence..."
                                class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <button type="button" @click="removeSkill(index)"
                                class="px-3 py-2 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-300 rounded-lg text-sm hover:bg-red-200 dark:hover:bg-red-900/50">
                                −
                            </button>
                        </div>
                    </template>
                </div>

                <button type="button" @click="addSkill"
                    class="w-full px-3 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:border-indigo-400 dark:hover:border-indigo-500 hover:text-indigo-600 dark:hover:text-indigo-400">
                    + Ajouter une compétence
                </button>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 font-medium">
                    Créer la catégorie
                </button>
                <a href="{{ route('admin.categories') }}" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
