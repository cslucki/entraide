@php
    $requestTerm = app()->getLocale() === 'en' ? __('marketplace.request_term') : ($T['request'] ?? __('marketplace.request_term'));
    $_reqOrgSlug = request()->route('organization');
    $_reqStoreAction = $_reqOrgSlug && Route::has('organization.requests.store') ? route('organization.requests.store', ['organization' => $_reqOrgSlug]) : route('requests.store');
    $_reqAiFormulateAction = $_reqOrgSlug && Route::has('organization.requests.ai-formulate') ? route('organization.requests.ai-formulate', ['organization' => $_reqOrgSlug]) : route('requests.ai-formulate');
    $pointMin = $organization->servicePointsMin();
    $pointMax = $organization->servicePointsMax();
    $pointHelpContext = ['organization' => $organization->name, 'min' => $pointMin, 'max' => $pointMax];
@endphp

<x-page :heading="__('marketplace.request_create_heading', ['request' => $requestTerm])" width="3xl">

        <!-- Note pédagogique -->
        <div class="mb-6 flex gap-3 bg-green-50 dark:bg-green-900/30 rounded-xl p-4 text-sm text-green-700 dark:text-green-300">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="font-semibold mb-1">{{ __('marketplace.request_intro_title', ['request' => $requestTerm]) }}</p>
                <p class="opacity-80">{{ __('marketplace.request_intro_body') }}</p>
            </div>
        </div>

        <div x-data="{
            loading: false,
            suggestion: null,
            error: null,
            canFormulate: false,
            errorMessageFallback: '',
            init() {
                this.errorMessageFallback = this.$el.dataset.errorMessage || '';
                this.refreshCanFormulate();
                document.addEventListener('input', (event) => {
                    if ((event.target.name === 'title' || event.target.name === 'description') && event.target.closest('form[data-marketplace-validation]')) {
                        this.refreshCanFormulate();
                    }
                });
            },
            refreshCanFormulate() {
                const form = document.querySelector('form[data-marketplace-validation]');
                const title = form?.querySelector('[name=\'title\']')?.value?.trim() || '';
                const description = form?.querySelector('[name=\'description\']')?.value?.trim() || '';
                this.canFormulate = title !== '' || description !== '';
            },
            async formulate() {
                this.loading = true;
                this.error = null;
                this.suggestion = null;
                try {
                    const form = document.querySelector('form[data-marketplace-validation]');
                    const payload = new FormData();
                    payload.append('title', form.querySelector('[name=\'title\']')?.value || '');
                    payload.append('description', form.querySelector('[name=\'description\']')?.value || '');
                    const token = document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || '';
                    const response = await fetch('{{ $_reqAiFormulateAction }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                        body: payload
                    });
                    const data = await response.json();
                    if (!response.ok) {
                        this.error = data.error || this.errorMessageFallback;
                        return;
                    }
                    this.suggestion = data.suggestion;
                } catch (exception) {
                    this.error = this.errorMessageFallback;
                } finally {
                    this.loading = false;
                }
            },
            applySuggestion() {
                if (!this.suggestion) return;
                const form = document.querySelector('form[data-marketplace-validation]');
                const title = form.querySelector('[name=\'title\']');
                const description = form.querySelector('[name=\'description\']');
                const category = form.querySelector('[name=\'category_id\']');
                if (title && this.suggestion.title) {
                    title.value = this.suggestion.title;
                    title.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (description && this.suggestion.description) {
                    description.value = this.suggestion.description;
                    description.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (category && this.suggestion.category_id && category.querySelector(`option[value="${this.suggestion.category_id}"]`)) {
                    category.value = this.suggestion.category_id;
                    category.dispatchEvent(new Event('change', { bubbles: true }));
                }
                this.suggestion = null;
                this.error = null;
            },
            dismissSuggestion() {
                this.suggestion = null;
                this.error = null;
            }
        }" data-request-ai-formulation data-error-message="{{ __('ai.request_formulation_error') }}"
             class="mb-6 rounded-xl border border-indigo-200 bg-white p-4 dark:border-indigo-600 dark:bg-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300">{{ __('ai.request_formulate_cta_title') }}</p>
                <button type="button" @click="formulate()" :disabled="loading || !canFormulate"
                    class="rounded-lg bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 transition hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-indigo-900/30 dark:text-indigo-300 dark:hover:bg-indigo-900/50">
                    <span x-show="!loading">✨ {{ __('ai.request_formulate_cta') }}</span>
                    <span x-show="loading">{{ __('ai.request_formulating') }}...</span>
                </button>
            </div>
            <p x-show="!canFormulate" class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.request_formulation_intention_hint') }}</p>

            <div x-show="suggestion" x-transition class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-700 dark:bg-indigo-900/20">
                <h4 class="text-sm font-semibold text-indigo-800 dark:text-indigo-200">{{ __('ai.request_suggestion_title') }}</h4>
                <div class="mt-2 space-y-2 text-sm text-indigo-700 dark:text-indigo-300">
                    <p><strong>{{ __('marketplace.title') }} :</strong> <span x-text="suggestion?.title"></span></p>
                    <p class="whitespace-pre-line"><strong>{{ __('marketplace.description') }} :</strong> <span x-text="suggestion?.description"></span></p>
                    <p x-show="suggestion?.category_id"><strong>{{ __('marketplace.category') }} :</strong> <span x-text="suggestion?.category_label"></span></p>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" @click="applySuggestion()" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-700">{{ __('ai.request_apply_suggestion') }}</button>
                    <button type="button" @click="dismissSuggestion()" class="rounded-lg border border-gray-300 px-4 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">{{ __('ai.request_dismiss_suggestion') }}</button>
                </div>
            </div>

            <div x-show="error" x-transition class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300" x-text="error"></div>
        </div>

        @if($errors->any())
        <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            <ul class="list-disc ml-4">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <x-marketplace-form-validation :attribute-labels="__('marketplace.validation_attributes')" />

        <form method="POST" action="{{ $_reqStoreAction }}" enctype="multipart/form-data" data-marketplace-validation
              x-data="{ selectedCategory: '{{ old('category_id', '') }}', files: [] }">
            @csrf

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.title') }} {{ __('marketplace.required') }}</label>
                <input type="text" name="title" value="{{ old('title') }}" required minlength="10" maxlength="255"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.request_description', ['request' => $requestTerm]) }} {{ __('marketplace.required') }}</label>
                <textarea name="description" rows="5" required minlength="100"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">{{ old('description') }}</textarea>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.category') }} {{ __('marketplace.required') }}</label>
                <select name="category_id" required x-model="selectedCategory"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    <option value="">{{ __('marketplace.select') }}</option>
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" @selected(old('category_id') === $cat->id)>{{ $cat->name_b2c }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('marketplace.delivery_mode') }} {{ __('marketplace.required') }}</label>
                <div class="flex gap-3">
                    @foreach(__('marketplace.delivery') as $val => $label)
                    <label class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 border rounded-lg cursor-pointer text-sm font-medium hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/30 dark:text-gray-300 border-gray-200 dark:border-gray-600 transition">
                        <input type="radio" name="delivery_mode" value="{{ $val }}" {{ old('delivery_mode') === $val ? 'checked' : '' }} required class="sr-only">
                        {{ $label }}
                    </label>
                    @endforeach
                </div>
            </div>

            <!-- Explication du système de points -->
            <div class="mb-5 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl text-sm text-amber-800 dark:text-amber-200">
                <p class="font-semibold mb-1">{{ __('marketplace.points_help_title') }}</p>
                <p class="mb-2 opacity-90">{{ __('marketplace.points_request_body', ['organization' => $organization->name]) }}</p>
                <ul class="space-y-0.5 mb-2 ml-2 opacity-90">
                    <li>{{ __('marketplace.points_one_minute') }}</li>
                    @if($pointMin !== null)
                        <li>{{ __('marketplace.points_minimum_allowed', $pointHelpContext) }}</li>
                    @endif
                    @if($pointMax !== null)
                        <li>{{ __('marketplace.points_maximum_allowed', $pointHelpContext) }}</li>
                    @endif
                </ul>
                <p class="opacity-90 mb-3">{{ __('marketplace.request_points_hint') }}</p>

                <hr class="border-amber-200 dark:border-amber-700/50 my-3">
                
                <p class="opacity-90 text-xs">
                    {{ __('marketplace.profile_tip_before') }}
                    <a href="{{ route('profile.edit') }}" class="font-semibold underline decoration-2 underline-offset-2 hover:text-amber-900 dark:hover:text-amber-100">{{ __('marketplace.public_profile') }}</a>
                    {{ __('marketplace.profile_tip_after') }}
                </p>
            </div>

            <div class="mb-5 grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.budget_min') }} {{ __('marketplace.required') }}</label>
                    <input type="number" name="budget_min" value="{{ old('budget_min') }}" @if($pointMin !== null) min="{{ $pointMin }}" @endif @if($pointMax !== null) max="{{ $pointMax }}" @endif required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.budget_max') }} <span class="text-gray-400">{{ __('marketplace.optional') }}</span></label>
                    <input type="number" name="budget_max" value="{{ old('budget_max') }}" @if($pointMin !== null) min="{{ $pointMin }}" @endif @if($pointMax !== null) max="{{ $pointMax }}" @endif
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            <!-- Fourchettes indicatives -->
            <div x-show="selectedCategory" class="mb-5 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                <p class="text-xs font-semibold text-green-700 dark:text-green-300 mb-2">{{ __('marketplace.indicative_ranges_level') }}</p>
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
                    {{ __('marketplace.attachments') }} <span class="text-gray-400">{{ __('marketplace.attachments_help') }}</span>
                </label>
                <label class="flex flex-col items-center justify-center w-full h-28 border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors bg-gray-50 dark:bg-gray-800/50">
                    <svg class="w-6 h-6 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('marketplace.attachments_add') }}</span>
                    <span class="text-xs text-gray-400 mt-0.5">{{ __('marketplace.attachments_types') }}</span>
                    <input id="attachments-input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx"
                        class="hidden" @change="addFiles($event)">
                </label>
                <ul x-show="files.length > 0" class="mt-2 space-y-1">
                    <template x-for="(file, i) in files" :key="i">
                        <li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700 px-3 py-1.5 rounded-lg">
                            <span x-text="icon(file)" class="text-base"></span>
                            <span class="flex-1 truncate" x-text="file.name"></span>
                            <span class="text-xs text-gray-400" x-text="@js(__('marketplace.file_size_mb', ['size' => '__SIZE__'])).replace('__SIZE__', (file.size/1024/1024).toFixed(1))"></span>
                            <button type="button" @click="remove(i)" class="text-red-400 hover:text-red-600 text-lg leading-none">&times;</button>
                        </li>
                    </template>
                </ul>
                @error('attachments.*')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.deadline') }} <span class="text-gray-400">{{ __('marketplace.optional') }}</span></label>
                <input type="date" name="deadline" value="{{ old('deadline') }}" min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="mb-8">
                <label for="relay_loop_id" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('requests.relay_loop_label') }} <span class="font-normal text-gray-400">{{ __('marketplace.optional') }}</span></label>
                <select id="relay_loop_id" name="relay_loop_id"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">{{ __('requests.relay_loop_none') }}</option>
                    @foreach($relayLoops as $relayLoop)
                        <option value="{{ $relayLoop->id }}" @selected(old('relay_loop_id') === $relayLoop->id)>{{ $relayLoop->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('requests.relay_loop_help') }}</p>
                @error('relay_loop_id')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">{{ __('marketplace.publish_request', ['request' => $requestTerm]) }}</button>
                <a href="{{ route('dashboard') }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('ui.cancel') }}</a>
            </div>
        </form>
</x-page>
