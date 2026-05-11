<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-4">Faire une {{ $T['request'] }}</h1>

        <!-- Note pédagogique -->
        <div class="mb-6 flex gap-3 bg-green-50 dark:bg-green-900/30 rounded-xl p-4 text-sm text-green-700 dark:text-green-300">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="font-semibold mb-1">Une {{ $T['request'] }}, c'est un besoin que vous publiez pour trouver de l'aide parmi les membres.</p>
                <p class="opacity-80">Décrivez précisément ce dont vous avez besoin, le résultat attendu, et votre budget en points. Les membres intéressés vous proposeront un échange.</p>
            </div>
        </div>

        @if($errors->any())
        <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            <ul class="list-disc ml-4">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <form method="POST" action="{{ route('requests.store') }}" enctype="multipart/form-data"
              x-data="{ selectedCategory: '{{ old('category_id', '') }}', files: [] }">
            @csrf
            @php $tenant = $currentCommunity ?? $currentOrganization ?? null; @endphp
            @isset($tenant)
            <input type="hidden" name="community_id" value="{{ $tenant->id }}">
            @endisset

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre *</label>
                <input type="text" name="title" value="{{ old('title') }}" required maxlength="255"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description de votre {{ $T['request'] }} *</label>
                <textarea name="description" rows="5" required
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">{{ old('description') }}</textarea>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Catégorie *</label>
                <select name="category_id" required x-model="selectedCategory"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    <option value="">Sélectionner...</option>
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mode de prestation *</label>
                <div class="flex gap-3">
                    @foreach(['remote' => '🌐 À distance', 'onsite' => '📍 Sur site', 'both' => '🌐📍 Les deux'] as $val => $label)
                    <label class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 border rounded-lg cursor-pointer text-sm font-medium hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/30 dark:text-gray-300 border-gray-200 dark:border-gray-600 transition">
                        <input type="radio" name="delivery_mode" value="{{ $val }}" {{ old('delivery_mode') === $val ? 'checked' : '' }} required class="sr-only">
                        {{ $label }}
                    </label>
                    @endforeach
                </div>
            </div>

            <!-- Explication du système de points -->
            <div class="mb-5 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl text-sm text-amber-800 dark:text-amber-200">
                <p class="font-semibold mb-1">💡 Comment fonctionne le barème de points ?</p>
                <p class="mb-2 opacity-90">BouclePro est une plateforme de <strong>troc de compétences</strong> — les points ne sont pas de l'argent, mais une unité d'échange pour évaluer la valeur d'une demande d'aide et faciliter les échanges entre membres.</p>
                <p class="mb-2 opacity-90">Fourchettes indicatives (tout est négociable) :</p>
                <ul class="space-y-0.5 mb-2 ml-2 opacity-90">
                    <li><span class="font-medium">Essentiel</span> — 40 à 60 pts <span class="opacity-70">(20 à 30 min)</span></li>
                    <li><span class="font-medium">Standard</span> — 60 à 80 pts <span class="opacity-70">(30 à 45 min)</span></li>
                    <li><span class="font-medium">Complet</span> — 80 à 100 pts <span class="opacity-70">(45 à 60 min)</span></li>
                </ul>
                <p class="opacity-90 mb-3">Ces fourchettes sont indicatives — vous pouvez proposer le budget qui vous convient, les membres pourront négocier.</p>

                <hr class="border-amber-200 dark:border-amber-700/50 my-3">
                
                <p class="opacity-90 text-xs">
                    ✨ N'oubliez pas que vous pouvez présenter votre activité plus largement (liens site web, LinkedIn) sur votre 
                    <a href="{{ route('profile.edit') }}" class="font-semibold underline decoration-2 underline-offset-2 hover:text-amber-900 dark:hover:text-amber-100">profil public</a> 
                    pour qu'ils apparaissent dans l'annuaire.
                </p>
            </div>

            <div class="mb-5 grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Budget min (pts) *</label>
                    <input type="number" name="budget_min" value="{{ old('budget_min') }}" min="1" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Budget max (pts) <span class="text-gray-400">optionnel</span></label>
                    <input type="number" name="budget_max" value="{{ old('budget_max') }}" min="1"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            <!-- Fourchettes indicatives -->
            <div x-show="selectedCategory" class="mb-5 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                <p class="text-xs font-semibold text-green-700 dark:text-green-300 mb-2">Fourchettes indicatives par niveau :</p>
                @foreach($categories as $cat)
                <div x-show="selectedCategory === '{{ $cat->id }}'" class="flex gap-4 flex-wrap">
                    @foreach($cat->pointGuidelines as $g)
                    <div class="text-xs text-green-600 dark:text-green-400">
                        <span class="font-medium">{{ ucfirst($g->level) }}</span> : {{ $g->points_min }}–{{ $g->points_max }} pts <span class="text-green-400">({{ $g->duration_label }})</span>
                    </div>
                    @endforeach
                </div>
                @endforeach
            </div>

            {{-- Pièces jointes --}}
            <div class="mb-5"
                 x-data="{
                    files: [],
                    addFiles(e) {
                        const max = 5;
                        const newFiles = Array.from(e.target.files);
                        this.files = [...this.files, ...newFiles].slice(0, max);
                        // rebuild the file input with a DataTransfer
                        const dt = new DataTransfer();
                        this.files.forEach(f => dt.items.add(f));
                        e.target.files = dt.files;
                    },
                    remove(index) {
                        this.files.splice(index, 1);
                        const dt = new DataTransfer();
                        this.files.forEach(f => dt.items.add(f));
                        document.getElementById('attachments-input').files = dt.files;
                    },
                    icon(file) {
                        if (file.type.startsWith('image/')) return '🖼️';
                        if (file.type === 'application/pdf') return '📄';
                        if (file.type.includes('word')) return '📝';
                        if (file.type.includes('excel') || file.type.includes('spreadsheet')) return '📊';
                        return '📎';
                    }
                 }">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Pièces jointes <span class="text-gray-400">optionnelles — max 5 fichiers · 10 Mo chacun</span>
                </label>
                <label class="flex flex-col items-center justify-center w-full h-28 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors bg-gray-50 dark:bg-gray-800/50">
                    <svg class="w-6 h-6 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span class="text-sm text-gray-500 dark:text-gray-400">Cliquer pour ajouter des images ou documents</span>
                    <span class="text-xs text-gray-400 mt-0.5">JPG · PNG · PDF · Word · Excel</span>
                    <input id="attachments-input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"
                        class="hidden" @change="addFiles($event)">
                </label>
                <ul x-show="files.length > 0" class="mt-2 space-y-1">
                    <template x-for="(file, i) in files" :key="i">
                        <li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700 px-3 py-1.5 rounded-lg">
                            <span x-text="icon(file)" class="text-base"></span>
                            <span class="flex-1 truncate" x-text="file.name"></span>
                            <span class="text-xs text-gray-400" x-text="(file.size/1024/1024).toFixed(1) + ' Mo'"></span>
                            <button type="button" @click="remove(i)" class="text-red-400 hover:text-red-600 text-lg leading-none">&times;</button>
                        </li>
                    </template>
                </ul>
                @error('attachments.*')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date souhaitée <span class="text-gray-400">optionnelle</span></label>
                <input type="date" name="deadline" value="{{ old('deadline') }}" min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">Publier la {{ $T['request'] }}</button>
                <a href="{{ route('dashboard') }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Annuler</a>
            </div>
        </form>
    </div>
</x-app-layout>
