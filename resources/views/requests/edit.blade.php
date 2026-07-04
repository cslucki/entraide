<x-app-layout>
    @php
        $_isOrgRoute = str_starts_with(Route::currentRouteName(), 'organization.');
        $_reqOrgSlug = $_isOrgRoute ? $organization?->slug : null;
        $_reqUpdateAction = $_reqOrgSlug && Route::has('organization.requests.update') ? route('organization.requests.update', ['organization' => $_reqOrgSlug, 'request' => $request]) : route('requests.update', $request);
    @endphp
    <div class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">{{ __('dashboard.detail_request_title') }}</h1>

        @if($errors->any())
        <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            <ul class="list-disc ml-4">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <form method="POST" action="{{ $_reqUpdateAction }}" enctype="multipart/form-data"
              x-data="{ selectedCategory: '{{ old('category_id', $request->category_id) }}' }">
            @csrf @method('PUT')

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('dashboard.table_title') }} *</label>
                <input type="text" name="title" value="{{ old('title', $request->title) }}" required maxlength="255"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description *</label>
                <textarea name="description" rows="5" required
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">{{ old('description', $request->description) }}</textarea>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Catégorie *</label>
                <select name="category_id" required x-model="selectedCategory"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name_b2c }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mode de prestation *</label>
                <div class="flex gap-3">
                    @foreach(['remote' => '🌐 À distance', 'onsite' => '📍 Sur site', 'both' => '🌐📍 Les deux'] as $val => $label)
                    <label class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 border rounded-lg cursor-pointer text-sm font-medium hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/30 dark:text-gray-300 border-gray-200 dark:border-gray-600 transition">
                        <input type="radio" name="delivery_mode" value="{{ $val }}" {{ (old('delivery_mode', $request->delivery_mode) === $val) ? 'checked' : '' }} required class="sr-only">
                        {{ $label }}
                    </label>
                    @endforeach
                </div>
            </div>

            <div class="mb-5 grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Budget min *</label>
                    <input type="number" name="budget_min" value="{{ old('budget_min', $request->budget_min) }}" min="1" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Budget max <span class="text-gray-400">(optionnel)</span></label>
                    <input type="number" name="budget_max" value="{{ old('budget_max', $request->budget_max) }}" min="1"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            {{-- Pièces jointes --}}
            <div class="mb-5"
                 x-data="{
                    files: [],
                    addFiles(e) {
                        const max = 5 - {{ $request->attachments->count() }};
                        const newFiles = Array.from(e.target.files);
                        this.files = [...this.files, ...newFiles].slice(0, max);
                        const dt = new DataTransfer();
                        this.files.forEach(f => dt.items.add(f));
                        e.target.files = dt.files;
                    },
                    remove(index) {
                        this.files.splice(index, 1);
                        const dt = new DataTransfer();
                        this.files.forEach(f => dt.items.add(f));
                        document.getElementById('edit-attachments-input').files = dt.files;
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
                    Pièces jointes <span class="text-gray-400">(max 5 fichiers, optionnel)</span>
                </label>

                @if($request->attachments->isNotEmpty())
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach($request->attachments as $attachment)
                    <a href="{{ Storage::url($attachment->file_path) }}" target="_blank" class="inline-flex items-center gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-700 rounded-lg text-xs text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-600">
                        📎 {{ $attachment->original_name ?? $attachment->file_name }}
                    </a>
                    @endforeach
                </div>
                @endif

                <label class="flex flex-col items-center justify-center w-full h-28 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors bg-gray-50 dark:bg-gray-800/50">
                    <svg class="w-6 h-6 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span class="text-sm text-gray-500 dark:text-gray-400">Ajouter des fichiers</span>
                    <span class="text-xs text-gray-400 mt-0.5">JPG, PNG, PDF, DOC, XLS — max 10 Mo</span>
                    <input id="edit-attachments-input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"
                        class="hidden" @change="addFiles($event)">
                </label>
                <ul x-show="files.length > 0" class="mt-2 space-y-1">
                    <template x-for="(file, i) in files" :key="i">
                        <li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700 px-3 py-1.5 rounded-lg">
                            <span x-text="icon(file)"></span>
                            <span class="flex-1 truncate" x-text="file.name"></span>
                            <button type="button" @click="remove(i)" class="text-red-400 hover:text-red-600">&times;</button>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Délai <span class="text-gray-400">(optionnel)</span></label>
                <input type="date" name="deadline" value="{{ old('deadline', $request->deadline?->format('Y-m-d')) }}"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">Enregistrer</button>
                <a href="{{ $_reqOrgSlug ? route('organization.dashboard.requests', ['organization' => $_reqOrgSlug]) : route('dashboard.requests') }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Annuler</a>
            </div>
        </form>
    </div>
</x-app-layout>
