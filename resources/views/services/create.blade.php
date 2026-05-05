<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-4">Proposer un {{ $T['service'] }}</h1>

        <!-- Note pédagogique -->
        <div class="mb-6 flex gap-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-xl p-4 text-sm text-indigo-700 dark:text-indigo-300">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="font-semibold mb-1">Un {{ $T['service'] }}, c'est une compétence que vous proposez en échange de points.</p>
                <p class="opacity-80">Décrivez précisément ce que vous faites, comment, et ce que le membre obtiendra. Ce n'est pas une annonce de recherche d'emploi — si vous cherchez une mission, utilisez plutôt "Faire une demande".</p>
            </div>
        </div>

        @if($errors->any())
        <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            <ul class="list-disc ml-4">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <form method="POST" action="{{ route('services.store') }}" enctype="multipart/form-data"
              x-data="{
                selectedCategory: '{{ old('category_id', '') }}',
                guidelines: @js($categories->keyBy('id')->map(fn($c) => $c->pointGuidelines)),
                tags: '{{ old('tags', '') }}',
                tagList: [],
                tagInput: '',
                addTag() {
                    if (!this.tagInput.trim() || this.tagList.length >= 5) return;
                    this.tagList.push(this.tagInput.trim());
                    this.tags = this.tagList.join(',');
                    this.tagInput = '';
                },
                removeTag(i) { this.tagList.splice(i,1); this.tags = this.tagList.join(','); },
                init() { if(this.tags) this.tagList = this.tags.split(',').filter(t=>t); }
              }">
            @csrf
            @isset($currentCommunity)
            <input type="hidden" name="community_id" value="{{ $currentCommunity->id }}">
            @endisset

            <!-- Titre -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre <span class="text-red-500">*</span></label>
                <input type="text" name="title" value="{{ old('title') }}" required minlength="10" maxlength="255"
                    placeholder="Ex : Création de logo professionnel, Traduction FR→EN, Conseil comptable 1h…"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <!-- Description -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Description <span class="text-red-500">*</span>
                    <span class="text-gray-400 font-normal">(100 caractères minimum)</span>
                </label>
                <textarea name="description" rows="6" required minlength="100"
                    placeholder="Décrivez ce que vous proposez concrètement :&#10;- Ce que le membre reçoit exactement&#10;- Comment vous travaillez&#10;- Vos conditions (délai, format de livraison…)&#10;&#10;Exemple : Je réalise votre logo professionnel en 3 propositions avec retouches illimitées sous 5 jours. Formats livrés : PNG, SVG, PDF. Je travaille sur Figma et Illustrator."
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">{{ old('description') }}</textarea>
            </div>

            <!-- Catégorie -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Catégorie *</label>
                <select name="category_id" required x-model="selectedCategory"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    <option value="">Sélectionner...</option>
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ old('category_id') === $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Compétences -->
            <div class="mb-5" x-show="selectedCategory">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Compétences</label>
                @foreach($categories as $cat)
                <div x-show="selectedCategory === '{{ $cat->id }}'">
                    <div class="flex flex-wrap gap-2">
                        @foreach($cat->skills as $skill)
                        <label class="flex items-center gap-2 px-3 py-1.5 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/30 text-sm">
                            <input type="checkbox" name="skills[]" value="{{ $skill->id }}"
                                {{ in_array($skill->id, old('skills', [])) ? 'checked' : '' }}
                                class="text-indigo-600">
                            {{ $skill->name }}
                        </label>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            <!-- Images -->
            <div class="mb-5" x-data="{ images: [] }">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Images (max 5)</label>
                <input type="file" name="images[]" multiple accept="image/*"
                    @change="images = Array.from($event.target.files).slice(0, 5)"
                    class="block w-full text-sm text-gray-600 dark:text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-900/30 dark:file:text-indigo-300 hover:file:bg-indigo-100">
                <p class="text-xs text-gray-400 mt-1">JPG, PNG ou WebP — max 2 Mo par image</p>
                <div class="flex flex-wrap gap-2 mt-2">
                    <template x-for="(img, i) in images" :key="i">
                        <div class="relative w-20 h-20 border rounded overflow-hidden bg-gray-100 dark:bg-gray-700">
                            <img :src="URL.createObjectURL(img)" class="w-full h-full object-cover">
                        </div>
                    </template>
                </div>
            </div>

            <!-- Tags -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tags <span class="text-gray-400">(max 5)</span></label>
                <input type="hidden" name="tags" x-bind:value="tags">
                <div class="flex flex-wrap gap-2 mb-2">
                    <template x-for="(tag, i) in tagList" :key="i">
                        <span class="flex items-center gap-1 px-2 py-0.5 bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 rounded text-sm">
                            <span x-text="tag"></span>
                            <button type="button" @click="removeTag(i)" class="ml-1 text-indigo-400 hover:text-indigo-700">×</button>
                        </span>
                    </template>
                </div>
                <div class="flex gap-2" x-show="tagList.length < 5">
                    <input type="text" x-model="tagInput" @keydown.enter.prevent="addTag" placeholder="Ajouter un tag..."
                        class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    <button type="button" @click="addTag" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300">Ajouter</button>
                </div>
            </div>

            <!-- Mode de prestation -->
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

            <!-- Points -->
            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Points demandés *</label>
                <input type="number" name="points_cost" value="{{ old('points_cost') }}" min="40" max="100" required
                    class="w-40 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">

                <!-- Guidelines -->
                <div x-show="selectedCategory" class="mt-3 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg">
                    <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-300 mb-2">Fourchettes recommandées :</p>
                    @foreach($categories as $cat)
                    <div x-show="selectedCategory === '{{ $cat->id }}'" class="flex gap-4">
                        @foreach($cat->pointGuidelines as $g)
                        <div class="text-xs text-indigo-600 dark:text-indigo-400">
                            <span class="font-medium">{{ ucfirst($g->level) }}</span> : {{ $g->points_min }}–{{ $g->points_max }} pts <span class="text-indigo-400">({{ $g->duration_label }})</span>
                        </div>
                        @endforeach
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">Publier le service</button>
                <a href="{{ route('dashboard') }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Annuler</a>
            </div>
        </form>
    </div>
</x-app-layout>
