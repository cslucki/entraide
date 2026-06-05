<x-admin-layout :title="'Modifier — ' . $serviceRequest->title">
    <div class="mb-4">
        <a href="{{ route('admin.requests') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
            ← Retour aux demandes
        </a>
    </div>

    <div class="max-w-3xl">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
            <div class="mb-5 pb-4 border-b border-gray-100 dark:border-gray-700">
                <p class="text-xs text-gray-400">Auteur :
                    <span class="font-medium text-gray-600 dark:text-gray-300">{{ $serviceRequest->user?->name ?? 'Supprimé' }}</span>
                    &nbsp;·&nbsp; Créée le {{ $serviceRequest->created_at->format('d/m/Y') }}
                </p>
            </div>

            <form method="POST" action="{{ route('admin.requests.update', $serviceRequest) }}" enctype="multipart/form-data">
                @csrf @method('PUT')

                @if($errors->any())
                <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/30 rounded-lg text-sm text-red-700 dark:text-red-300">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
                @endif

                {{-- Titre --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre</label>
                    <input type="text" name="title" value="{{ old('title', $serviceRequest->title) }}" required maxlength="255"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>

                {{-- Description --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" rows="5" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">{{ old('description', $serviceRequest->description) }}</textarea>
                </div>

                {{-- Catégorie --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Catégorie</label>
                    <select name="category_id" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">— Choisir —</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $serviceRequest->category_id) == $cat->id ? 'selected' : '' }}>
                            {{ $cat->name_b2c }}
                        </option>
                        @endforeach
                    </select>
                </div>

                {{-- Mode + Budget + Deadline + Statut --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mode de prestation</label>
                        <select name="delivery_mode" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="remote" {{ old('delivery_mode', $serviceRequest->delivery_mode) === 'remote' ? 'selected' : '' }}>À distance</option>
                            <option value="onsite" {{ old('delivery_mode', $serviceRequest->delivery_mode) === 'onsite' ? 'selected' : '' }}>Sur site</option>
                            <option value="both"   {{ old('delivery_mode', $serviceRequest->delivery_mode) === 'both'   ? 'selected' : '' }}>Les deux</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Statut</label>
                        <select name="status" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="open"        {{ old('status', $serviceRequest->status) === 'open'        ? 'selected' : '' }}>Ouverte</option>
                            <option value="in_progress" {{ old('status', $serviceRequest->status) === 'in_progress' ? 'selected' : '' }}>En cours</option>
                            <option value="closed"      {{ old('status', $serviceRequest->status) === 'closed'      ? 'selected' : '' }}>Clôturée</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Budget min (pts)</label>
                        <input type="number" name="budget_min" min="1" required
                            value="{{ old('budget_min', $serviceRequest->budget_min) }}"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Budget max (pts) <span class="text-gray-400">optionnel</span></label>
                        <input type="number" name="budget_max" min="1"
                            value="{{ old('budget_max', $serviceRequest->budget_max) }}"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date souhaitée <span class="text-gray-400">optionnelle</span></label>
                        <input type="date" name="deadline"
                            value="{{ old('deadline', $serviceRequest->deadline?->format('Y-m-d')) }}"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                {{-- Pièces jointes existantes --}}
                @if($serviceRequest->attachments->isNotEmpty())
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Pièces jointes existantes</label>
                    <div class="space-y-2">
                        @foreach($serviceRequest->attachments as $att)
                        <div class="flex items-center gap-3 p-2 border border-gray-200 dark:border-gray-600 rounded-lg">
                            @if($att->isImage())
                            <img src="{{ $att->url }}" class="w-12 h-12 object-cover rounded" alt="">
                            @elseif($att->iconClass() === 'pdf')
                            <span class="text-2xl text-red-500">📄</span>
                            @elseif($att->iconClass() === 'word')
                            <span class="text-2xl text-blue-500">📝</span>
                            @else
                            <span class="text-2xl text-green-500">📊</span>
                            @endif
                            <span class="flex-1 text-sm text-gray-700 dark:text-gray-300 truncate">{{ $att->original_name }}</span>
                            <label class="flex items-center gap-1.5 text-xs text-red-500 cursor-pointer">
                                <input type="checkbox" name="delete_attachments[]" value="{{ $att->id }}" class="rounded border-red-300 text-red-500">
                                Supprimer
                            </label>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Nouvelles pièces jointes --}}
                <div class="mb-6"
                     x-data="{
                        files: [],
                        addFiles(e) {
                            const newFiles = Array.from(e.target.files);
                            this.files = [...this.files, ...newFiles].slice(0, 5);
                            const dt = new DataTransfer();
                            this.files.forEach(f => dt.items.add(f));
                            e.target.files = dt.files;
                        },
                        remove(index) {
                            this.files.splice(index, 1);
                            const dt = new DataTransfer();
                            this.files.forEach(f => dt.items.add(f));
                            document.getElementById('admin-att-input').files = dt.files;
                        },
                        icon(file) {
                            if (file.type.startsWith('image/')) return '🖼️';
                            if (file.type === 'application/pdf') return '📄';
                            if (file.type.includes('word')) return '📝';
                            return '📊';
                        }
                     }">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Ajouter des pièces jointes <span class="font-normal text-gray-400">max 5 · 10 Mo chacun</span>
                    </label>
                    <label class="flex flex-col items-center justify-center w-full h-20 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 transition-colors bg-gray-50 dark:bg-gray-800/50">
                        <span class="text-sm text-gray-400">JPG · PNG · PDF · Word · Excel</span>
                        <input id="admin-att-input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"
                            class="hidden" @change="addFiles($event)">
                    </label>
                    <ul x-show="files.length > 0" class="mt-2 space-y-1">
                        <template x-for="(file, i) in files" :key="i">
                            <li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700 px-3 py-1.5 rounded-lg">
                                <span x-text="icon(file)"></span>
                                <span class="flex-1 truncate" x-text="file.name"></span>
                                <span class="text-xs text-gray-400" x-text="(file.size/1024/1024).toFixed(1) + ' Mo'"></span>
                                <button type="button" @click="remove(i)" class="text-red-400 hover:text-red-600 text-lg leading-none">&times;</button>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="flex gap-3">
                    <button type="submit"
                        class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 transition-colors">
                        Enregistrer les modifications
                    </button>
                    <a href="{{ route('admin.requests') }}"
                        class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-sm text-gray-600 dark:text-gray-400 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        Annuler
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
