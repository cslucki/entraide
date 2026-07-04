<x-page title="{{ __('blog.title_create') }}" heading="{{ __('blog.heading_create') }}" width="3xl">

    @php
        $_blogIndexHref = request()->route('organization') && Route::has('organization.blog.index') ? route('organization.blog.index', ['organization' => request()->route('organization')]) : route('blog.index');
        $_aiGenerateRoute = Route::has('organization.blog.ai-generate') && request()->route('organization') ? route('organization.blog.ai-generate', ['organization' => request()->route('organization')]) : route('blog.ai-generate');
        $_createDraftRoute = Route::has('organization.blog.create-draft') && request()->route('organization') ? route('organization.blog.create-draft', ['organization' => request()->route('organization')]) : route('blog.create-draft');
    @endphp
    <div class="hidden sm:block mb-6">
        <a href="{{ $_blogIndexHref }}" class="text-sm text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400">← {{ __('blog.back_to_blog') }}</a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
        <div
            x-data="createWizard"
            x-init="csrfToken = '{{ csrf_token() }}'; generatingMsg = '{{ __('blog.generating') }}'; creatingMsg = '{{ __('blog.creating_draft') }}'; errorMsg = '{{ __('blog.communication_error') }}'"
        >
            <form class="space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_title') }}</label>
                    <input type="text" x-model="title"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_summary') }}</label>
                    <textarea x-model="summary" rows="2" maxlength="500" placeholder="{{ __('blog.placeholder_summary') }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('blog.label_category_optional') }}</label>
                    <select x-model="categoryId"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                        <option value="">{{ __('blog.option_none') }}</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->displayName('blog') }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="error" x-text="error" class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-4 py-3" role="alert"></div>

                <div x-show="!loading" class="flex flex-col sm:flex-row gap-3 pt-2">
                    <button type="button" @click="generateWithAi()" :disabled="!canGenerate"
                        class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-400 disabled:cursor-not-allowed text-white font-semibold rounded-lg transition">
                        {{ __('blog.btn_generate_ai') }}
                    </button>
                    <button type="button" @click="writeMyself()" :disabled="!title.trim()"
                        class="px-6 py-3 border border-gray-300 dark:border-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300 font-semibold rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('blog.btn_write_myself') }}
                    </button>
                    <a href="{{ $_blogIndexHref }}" class="px-6 py-3 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('blog.btn_cancel') }}
                    </a>
                </div>

                <div x-show="loading" class="flex items-center justify-center gap-3 py-8 text-gray-500 dark:text-gray-400">
                    <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span x-text="loadingMessage">{{ __('blog.creating_draft') }}</span>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('createWizard', () => ({
            title: '',
            summary: '',
            categoryId: '',
            loading: false,
            error: '',
            csrfToken: '',
            routes: @json([
                'aiGenerate' => $_aiGenerateRoute,
                'createDraft' => $_createDraftRoute,
            ]),

            get canGenerate() {
                return this.title.trim() !== '' && this.summary.trim() !== '';
            },

            get loadingMessage() {
                return this.title.trim() ? this.generatingMsg : this.creatingMsg;
            },

            async generateWithAi() {
                if (!this.canGenerate) return;
                this.loading = true;
                this.error = '';

                try {
                    const response = await fetch(this.routes.aiGenerate, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            title: this.title,
                            summary: this.summary,
                            category_id: this.categoryId || null,
                        }),
                    });

                    const data = await response.json();

                    if (data.edit_url) {
                        window.location.href = data.edit_url;
                    } else if (data.error) {
                        this.error = data.error;
                    }
                } catch (e) {
                    this.error = this.errorMsg;
                } finally {
                    this.loading = false;
                }
            },

            async writeMyself() {
                if (!this.title.trim()) return;
                this.loading = true;
                this.error = '';

                try {
                    const response = await fetch(this.routes.createDraft, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            title: this.title,
                            summary: this.summary,
                            category_id: this.categoryId || null,
                        }),
                    });

                    const data = await response.json();

                    if (data.edit_url) {
                        window.location.href = data.edit_url;
                    } else if (data.error) {
                        this.error = data.error;
                    }
                } catch (e) {
                    this.error = this.errorMsg;
                } finally {
                    this.loading = false;
                }
            },
        }));
    });
    </script>
</x-page>
