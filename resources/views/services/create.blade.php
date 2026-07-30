@php
    $serviceTerm = app()->getLocale() === 'en' ? __('marketplace.service_term') : ($T['service'] ?? __('marketplace.service_term'));
    $_svcOrgSlug = request()->route('organization');
    $_svcStoreAction = $_svcOrgSlug && Route::has('organization.services.store') ? route('organization.services.store', ['organization' => $_svcOrgSlug]) : route('services.store');
    $_svcAiFormulateAction = $_svcOrgSlug && Route::has('organization.services.ai-formulate') ? route('organization.services.ai-formulate', ['organization' => $_svcOrgSlug]) : route('services.ai-formulate');
    $pointMin = $organization->servicePointsMin();
    $pointMax = $organization->servicePointsMax();
    $pointHelpContext = ['organization' => $organization->name, 'min' => $pointMin, 'max' => $pointMax];
@endphp

<x-page :heading="__('marketplace.service_create_heading', ['service' => $serviceTerm])" width="3xl">

        <!-- Note pédagogique -->
        <div class="mb-6 flex gap-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-xl p-4 text-sm text-indigo-700 dark:text-indigo-300">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                 <p class="font-semibold mb-1">{{ __('marketplace.service_intro_title', ['service' => $serviceTerm]) }}</p>
                 <p class="opacity-80">{{ __('marketplace.service_intro_body') }}</p>
            </div>
        </div>

        <!-- AI Formulation Panel -->
        <div x-data="{
            loading: false,
            suggestion: null,
            error: null,
            categoryLabel: '',
            canFormulate: false,
            errorMessageFallback: '',
            init() {
                this.errorMessageFallback = this.$el.dataset.errorMessage || '';
                const self = this;
                this.refreshCanFormulate();
                document.addEventListener('input', function(e) {
                    if ((e.target.name === 'title' || e.target.name === 'description') && e.target.closest('form[data-marketplace-validation]')) {
                        self.refreshCanFormulate();
                    }
                });
            },
            refreshCanFormulate() {
                const t = document.querySelector('[name=\'title\']')?.value?.trim() || '';
                const d = document.querySelector('[name=\'description\']')?.value?.trim() || '';
                this.canFormulate = t !== '' || d !== '';
            },
            async formulate() {
                this.loading = true;
                this.error = null;
                this.suggestion = null;
                try {
                    const form = document.querySelector('form[data-marketplace-validation]');
                    const fd = new FormData();
                    fd.append('title', form.querySelector('[name=\'title\']')?.value || '');
                    fd.append('description', form.querySelector('[name=\'description\']')?.value || '');
                    fd.append('category_id', form.querySelector('[name=\'category_id\']')?.value || '');
                    fd.append('delivery_mode', form.querySelector('[name=\'delivery_mode\']:checked')?.value || '');
                    fd.append('points_cost', form.querySelector('[name=\'points_cost\']')?.value || '');

                    const token = document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || '';
                    const resp = await fetch('{{ $_svcAiFormulateAction }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                        body: fd
                    });
                    const data = await resp.json();
                    if (!resp.ok) {
                        this.error = data.error || this.errorMessageFallback;
                        return;
                    }
                    this.suggestion = data.suggestion;
                    this.categoryLabel = this.getCategoryLabel(this.suggestion.category_id);
                } catch (e) {
                    this.error = this.errorMessageFallback;
                } finally {
                    this.loading = false;
                }
            },
            getCategoryLabel(catId) {
                const sel = document.querySelector('[name=\'category_id\']');
                if (!sel) return catId;
                const opt = sel.querySelector('option[value=\'' + catId + '\']');
                return opt ? opt.textContent : catId;
            },
            applySuggestion() {
                if (!this.suggestion) return;
                const form = document.querySelector('form[data-marketplace-validation]');
                if (this.suggestion.title) {
                    const titleEl = form.querySelector('[name=\'title\']');
                    if (titleEl) { titleEl.value = this.suggestion.title; titleEl.dispatchEvent(new Event('input', { bubbles: true })); }
                }
                if (this.suggestion.description_markdown) {
                    const ta = document.querySelector('textarea[name=\'description\'][data-tiptap-target]');
                    if (ta && ta.hasAttribute('data-tiptap-initialized')) {
                        document.dispatchEvent(new CustomEvent('bp:markdown-editor:set-content', {
                            detail: { name: 'description', markdown: this.suggestion.description_markdown }
                        }));
                    } else {
                        const descEl = form.querySelector('[name=\'description\']');
                        if (descEl) { descEl.value = this.suggestion.description_markdown; descEl.dispatchEvent(new Event('input', { bubbles: true })); }
                    }
                }
                if (this.suggestion.category_id) {
                    const catEl = form.querySelector('[name=\'category_id\']');
                    if (catEl) { catEl.value = this.suggestion.category_id; catEl.dispatchEvent(new Event('change', { bubbles: true })); }
                }
                if (this.suggestion.delivery_mode) {
                    const dmEl = form.querySelector('[name=\'delivery_mode\'][value=\'' + this.suggestion.delivery_mode + '\']');
                    if (dmEl) dmEl.checked = true;
                }
                if (this.suggestion.points_cost != null && this.suggestion.points_cost !== '') {
                    const ptsEl = form.querySelector('[name=\'points_cost\']');
                    if (ptsEl) { ptsEl.value = this.suggestion.points_cost; ptsEl.dispatchEvent(new Event('input', { bubbles: true })); }
                }
                this.suggestion = null;
                this.error = null;
            },
            dismissSuggestion() {
                this.suggestion = null;
                this.error = null;
            }
        }" data-error-message="{{ __('ai.service_formulation_error') }}" class="mb-6 p-4 bg-white dark:bg-gray-800 border border-indigo-200 dark:border-indigo-600 rounded-xl">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300">{{ __('ai.service_formulate_cta_title') }}</p>
                </div>
                <button type="button" @click="formulate()" :disabled="loading || !canFormulate"
                    class="px-4 py-2 text-sm font-medium rounded-lg transition
                        bg-indigo-50 hover:bg-indigo-100 text-indigo-700
                        dark:bg-indigo-900/30 dark:hover:bg-indigo-900/50 dark:text-indigo-300
                        disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg x-show="loading" class="inline w-4 h-4 animate-spin -ml-1 mr-1" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span x-show="!loading">✨ {{ __('ai.service_formulate_cta') }}</span>
                    <span x-show="loading">{{ __('ai.service_formulating') }}...</span>
                </button>
            </div>

            <p x-show="!canFormulate" class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.service_formulation_intention_hint') }}</p>

            <!-- Suggestion Panel -->
            <div x-show="suggestion" x-transition class="mt-4 p-4 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg border border-indigo-200 dark:border-indigo-700">
                <h4 class="text-sm font-semibold text-indigo-800 dark:text-indigo-200 mb-2">{{ __('ai.service_suggestion_title') }}</h4>
                <div class="text-sm text-indigo-700 dark:text-indigo-300 space-y-1">
                    <p><strong class="text-indigo-800 dark:text-indigo-200">{{ __('marketplace.title') }} :</strong> <span x-text="suggestion?.title"></span></p>
                    <p><strong class="text-indigo-800 dark:text-indigo-200">{{ __('marketplace.description') }} :</strong></p>
                    <div x-show="suggestion?.description_preview_html" class="prose prose-sm dark:prose-invert max-w-none max-h-64 overflow-y-auto mt-1 border border-indigo-100 dark:border-indigo-800 rounded p-3" x-html="suggestion?.description_preview_html"></div>
                    <p><strong class="text-indigo-800 dark:text-indigo-200">{{ __('marketplace.category') }} :</strong> <span x-text="categoryLabel"></span></p>
                    <p><strong class="text-indigo-800 dark:text-indigo-200">{{ __('marketplace.delivery_mode') }} :</strong> <span x-text="suggestion?.delivery_mode"></span></p>
                    <p><strong class="text-indigo-800 dark:text-indigo-200">{{ __('marketplace.points_requested') }} :</strong> <span x-text="suggestion?.points_cost"></span></p>
                </div>
                <div class="flex gap-2 mt-3">
                    <button type="button" @click="applySuggestion()"
                        class="px-4 py-1.5 text-sm font-medium bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                        {{ __('ai.service_apply_suggestion') }}
                    </button>
                    <button type="button" @click="dismissSuggestion()"
                        class="px-4 py-1.5 text-sm font-medium border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('ai.service_dismiss_suggestion') }}
                    </button>
                </div>
            </div>

            <!-- Error -->
            <div x-show="error" x-transition class="mt-3 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg text-sm text-red-700 dark:text-red-300" x-text="error"></div>
        </div>

        @if($errors->any())
        <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            <ul class="list-disc ml-4">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <x-marketplace-form-validation :attribute-labels="__('marketplace.validation_attributes')" />

        <form method="POST" action="{{ $_svcStoreAction }}" enctype="multipart/form-data" data-marketplace-validation
              x-data="{
                selectedCategory: '{{ old('category_id', '') }}',
                guidelines: @js($categories->keyBy('id')->map(fn($c) => $c->pointGuidelines)),
                tags: '{{ old('tags', '') }}',
                tagList: [],
                tagInput: '',
                addTag() {
                    if (!this.tagInput.trim() || this.tagList.length >= 10) return;
                    this.tagList.push(this.tagInput.trim());
                    this.tags = this.tagList.join(',');
                    this.tagInput = '';
                },
                removeTag(i) { this.tagList.splice(i,1); this.tags = this.tagList.join(','); },
                init() { if(this.tags) this.tagList = this.tags.split(',').filter(t=>t); }
              }">
            @csrf

            <!-- Titre -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.title') }} <span class="text-red-500">{{ __('marketplace.required') }}</span></label>
                <input type="text" name="title" value="{{ old('title') }}" required minlength="10" maxlength="255"
                    placeholder="{{ __('marketplace.title_service_placeholder') }}"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <!-- Description -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    {{ __('marketplace.description') }} <span class="text-red-500">{{ __('marketplace.required') }}</span>
                    <span class="text-gray-400 font-normal">{{ __('marketplace.min_chars', ['count' => 100]) }}</span>
                </label>
                <x-markdown-wysiwyg-editor name="description" :value="old('description')" :placeholder="__('marketplace.service_description_placeholder')" required minLength="100" rows="6" :invalid="$errors->has('description')" />
            </div>

            <!-- Catégorie -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.category') }} {{ __('marketplace.required') }}</label>
                <select name="category_id" required x-model="selectedCategory"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    <option value="">{{ __('marketplace.select') }}</option>
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ old('category_id') === $cat->id ? 'selected' : '' }}>{{ $cat->name_b2c }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Compétences -->
            <div class="mb-5" x-show="selectedCategory">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('marketplace.skills') }}</label>
                @foreach($categories as $cat)
                <div x-show="selectedCategory === '{{ $cat->id }}'">
                    <div class="flex flex-wrap gap-2">
                        @foreach($cat->skills as $skill)
                        <label class="flex items-center gap-2 px-3 py-1.5 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/30 text-sm dark:text-gray-300">
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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.images') }}</label>
                <input type="file" name="images[]" multiple accept="image/*"
                    @change="images = Array.from($event.target.files).slice(0, 5)"
                    class="block w-full text-sm text-gray-600 dark:text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-900/30 dark:file:text-indigo-300 hover:file:bg-indigo-100">
                <p class="text-xs text-gray-400 mt-1">{{ __('marketplace.image_help') }}</p>
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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.tags') }} <span class="text-gray-400">{{ __('marketplace.max_10') }}</span></label>
                <input type="hidden" name="tags" x-bind:value="tags">
                <div class="flex flex-wrap gap-2 mb-2">
                    <template x-for="(tag, i) in tagList" :key="i">
                        <span class="flex items-center gap-1 px-2 py-0.5 bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 rounded text-sm">
                            <span x-text="tag"></span>
                            <button type="button" @click="removeTag(i)" class="ml-1 text-indigo-400 hover:text-indigo-700">×</button>
                        </span>
                    </template>
                </div>
                <div class="flex gap-2" x-show="tagList.length < 10">
                    <input type="text" x-model="tagInput" @keydown.enter.prevent="addTag" placeholder="{{ __('marketplace.add_tag_placeholder') }}"
                        class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    <button type="button" @click="addTag" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300">{{ __('marketplace.add') }}</button>
                </div>
            </div>

            <!-- Mode de prestation -->
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

            <!-- Points -->
            <div class="mb-8">
                <!-- Explication du système de points -->
                <div class="mb-4 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-semibold mb-1">{{ __('marketplace.points_help_title') }}</p>
                    <p class="mb-2 opacity-90">{{ __('marketplace.points_service_body', ['organization' => $organization->name]) }}</p>
                    <ul class="space-y-0.5 mb-3 ml-2 opacity-90">
                        <li>{{ __('marketplace.points_one_minute') }}</li>
                        @if($pointMin !== null)
                            <li>{{ __('marketplace.points_minimum_allowed', $pointHelpContext) }}</li>
                        @endif
                        @if($pointMax !== null)
                            <li>{{ __('marketplace.points_maximum_allowed', $pointHelpContext) }}</li>
                        @endif
                    </ul>
                    
                    <hr class="border-amber-200 dark:border-amber-700/50 my-3">
                    
                    <p class="opacity-90 text-xs">
                        {{ __('marketplace.profile_tip_before') }}
                        <a href="{{ route('profile.edit') }}" class="font-semibold underline decoration-2 underline-offset-2 hover:text-amber-900 dark:hover:text-amber-100">{{ __('marketplace.public_profile') }}</a>
                        {{ __('marketplace.profile_tip_after') }}
                    </p>
                </div>

                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.points_requested') }} {{ __('marketplace.required') }}</label>
                <input type="number" name="points_cost" value="{{ old('points_cost') }}" @if($pointMin !== null) min="{{ $pointMin }}" @endif @if($pointMax !== null) max="{{ $pointMax }}" @endif required
                    class="w-40 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">

                <!-- Guidelines par catégorie -->
                <div x-show="selectedCategory" class="mt-3 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg">
                    <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-300 mb-2">{{ __('marketplace.recommended_ranges_category') }}</p>
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
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">{{ __('marketplace.publish_service') }}</button>
                <a href="{{ route('dashboard') }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('ui.cancel') }}</a>
            </div>
        </form>
</x-page>
