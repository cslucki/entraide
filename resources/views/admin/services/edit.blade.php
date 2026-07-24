<x-admin-layout :title="'Modifier — ' . $service->title">
    <div class="mb-4">
        <a href="{{ route('admin.services') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
            ← Retour aux services
        </a>
    </div>

    <div class="max-w-3xl">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
            <div class="mb-5 pb-4 border-b border-gray-100 dark:border-gray-700">
                <p class="text-xs text-gray-400">Créé le {{ $service->created_at->format('d/m/Y') }}</p>
            </div>

            <form method="POST" action="{{ route('admin.services.update', $service) }}">
                @csrf @method('PUT')

                @if($errors->any())
                <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 rounded-lg text-sm text-red-700 dark:text-red-300">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
                @endif

                {{-- Propriétaire --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Propriétaire</label>
                    <select name="user_id" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        @foreach($users as $u)
                        <option value="{{ $u->id }}" {{ old('user_id', $service->user_id) == $u->id ? 'selected' : '' }}>
                            {{ $u->full_name }} ({{ $u->email }})
                        </option>
                        @endforeach
                    </select>
                </div>

                {{-- Organisation --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Organisation</label>
                    <select name="organization_id" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        @foreach($organizations as $org)
                        <option value="{{ $org->id }}" {{ old('organization_id', $service->organization_id) == $org->id ? 'selected' : '' }}>
                            {{ $org->name }}
                        </option>
                        @endforeach
                    </select>
                </div>

                {{-- Titre --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre</label>
                    <input type="text" name="title" value="{{ old('title', $service->title) }}" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>

                {{-- Description --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" rows="5" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">{{ old('description', $service->description) }}</textarea>
                </div>

                {{-- Catégorie + Compétences --}}
                <div class="mb-4" x-data="{
                    category: '{{ old('category_id', $service->category_id) }}',
                    selected: {{ json_encode($service->skills->pluck('id')->toArray()) }},
                    visibleSkills() {
                        if (!this.category) return [];
                        return this.skills.filter(s => s.category_id === this.category);
                    },
                    skills: {{ json_encode($skills->map(fn($s) => ['id' => $s->id, 'name' => $s->name, 'category_id' => $s->category_id, 'category_name' => $s->category?->name])->values()->toArray()) }}
                }">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Catégorie</label>
                    <select name="category_id" x-model="category" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">— Choisir une catégorie —</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $service->category_id) == $cat->id ? 'selected' : '' }}>
                            {{ $cat->name_b2c }}
                        </option>
                        @endforeach
                    </select>

                    <div x-show="category" class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Compétences</label>
                        <template x-if="visibleSkills().length === 0">
                            <p class="text-sm text-gray-400 italic">Aucune compétence dans cette catégorie.</p>
                        </template>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-48 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded-lg p-3"
                            x-show="visibleSkills().length > 0">
                            <template x-for="skill in visibleSkills()" :key="skill.id">
                                <label class="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="checkbox" name="skills[]" :value="skill.id"
                                        :checked="selected.includes(skill.id)"
                                        class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    <span class="text-gray-700 dark:text-gray-300" x-text="skill.name"></span>
                                    <span class="text-xs text-gray-400" x-text="'(' + skill.category_name + ')'"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Tags --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Tags <span class="font-normal text-gray-400">(séparés par des virgules, max 5)</span>
                    </label>
                    <input type="text" name="tags"
                        value="{{ old('tags', $service->tags->pluck('name')->implode(', ')) }}"
                        placeholder="ex : photographie, retouche, portrait"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>

                {{-- Mode + Points + Statut --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mode de prestation</label>
                        <select name="delivery_mode" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="remote"  {{ old('delivery_mode', $service->delivery_mode) === 'remote'  ? 'selected' : '' }}>À distance</option>
                            <option value="onsite"  {{ old('delivery_mode', $service->delivery_mode) === 'onsite'  ? 'selected' : '' }}>Sur site</option>
                            <option value="both"    {{ old('delivery_mode', $service->delivery_mode) === 'both'    ? 'selected' : '' }}>Les deux</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Points demandés</label>
                        <input type="number" name="points_cost" min="40" max="100"
                            value="{{ old('points_cost', $service->points_cost) }}" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Statut</label>
                        <select name="status" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="active" {{ old('status', $service->status) === 'active' ? 'selected' : '' }}>Actif</option>
                            <option value="paused" {{ old('status', $service->status) === 'paused' ? 'selected' : '' }}>En pause</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit"
                        class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 transition-colors">
                        Enregistrer les modifications
                    </button>
                    <a href="{{ route('admin.services') }}"
                        class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-sm text-gray-600 dark:text-gray-400 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        Annuler
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
