<x-app-layout>
    @php
        $_blogRoute = function ($name, $parameters = []) {
            $orgSlug = request()->route('organization');
            if (! $orgSlug || ! Route::has('organization.blog.'.$name)) {
                return route('blog.'.$name, $parameters);
            }
            return route('organization.blog.'.$name, array_merge(['organization' => $orgSlug], $parameters));
        };
    @endphp
    <x-slot name="title">{{ __('blog.title_edit', ['title' => $post->title]) }}</x-slot>

    <div class="max-w-7xl mx-auto px-4 py-8">

        <div class="mb-6">
            <a href="{{ $_blogRoute('show', ['post' => $post]) }}" class="text-sm text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400">← {{ __('blog.back_to_article') }}</a>
        </div>

        {{-- Editor layout: main panel + resize handle + sidebar --}}
        <div class="flex flex-col md:flex-row gap-0"
             x-data="{
                cards: [
                    { key: 'boucle', label: @js(__('blog.sidebar_boucle')), open: false, placeholder: @js(__('blog.sidebar_boucle_placeholder')) },
                    { key: 'coecriture', label: @js(__('blog.sidebar_co_ecriture')), open: false, placeholder: @js(__('blog.sidebar_co_ecriture_placeholder')) },
                    { key: 'annotations', label: @js(__('blog.sidebar_annotations')), open: true, placeholder: @js(__('blog.sidebar_annotations_placeholder')) },
                ],
                width: 280,
                resizing: false,

                toggle(key) {
                    const card = this.cards.find(c => c.key === key);
                    if (!card) return;
                    card.open = !card.open;
                    localStorage.setItem('editor_sidebar_card_' + key, card.open ? '1' : '0');
                },

                startResize(e) {
                    this.resizing = true;
                    const startX = e.clientX;
                    const startWidth = this.width;
                    const self = this;

                    const onMove = (e) => {
                        if (!self.resizing) return;
                        self.width = Math.max(200, Math.min(480, startWidth + startX - e.clientX));
                    };

                    const onUp = () => {
                        self.resizing = false;
                        localStorage.setItem('editor_sidebar_width', self.width);
                        window.removeEventListener('mousemove', onMove);
                        window.removeEventListener('mouseup', onUp);
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                    };

                    window.addEventListener('mousemove', onMove);
                    window.addEventListener('mouseup', onUp);
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                },

                init() {
                    const stored = localStorage.getItem('editor_sidebar_width');
                    if (stored) this.width = parseInt(stored, 10);
                    this.cards.forEach(c => {
                        const v = localStorage.getItem('editor_sidebar_card_' + c.key);
                        if (v !== null) c.open = v === '1';
                    });
                },
             }"
             x-init="init()">

            {{-- Main article panel --}}
            <div class="flex-1 min-w-0">

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">{{ __('blog.heading_edit') }}</h1>

            <form action="{{ $_blogRoute('update', ['post' => $post]) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                @csrf @method('PUT')

                @if($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">
                    <p class="font-semibold">{{ __('blog.error_alert') }}</p>
                </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_title') }}</label>
                    <input type="text" name="title" value="{{ old('title', $post->title) }}"
                        class="w-full px-3 py-2 border @error('title') border-red-500 ring-1 ring-red-500 dark:border-red-500 @else border-gray-300 dark:border-gray-600 @enderror rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    @error('title')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_summary') }}</label>
                    <textarea name="summary" rows="2" maxlength="500"
                        class="w-full px-3 py-2 border @error('summary') border-red-500 ring-1 ring-red-500 dark:border-red-500 @else border-gray-300 dark:border-gray-600 @enderror rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">{{ old('summary', $post->summary) }}</textarea>
                    @error('summary')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_content') }}</label>
                    <x-blog-editor
                        name="content"
                        :value="old('content', $post->content)"
                        :post-id="$post->id"
                        :invalid="$errors->has('content')"
                        :route-ai-generate="$_blogRoute('ai-generate')"
                        :route-ai-correct="$_blogRoute('ai-correct')"
                        :route-ai-remaining="$_blogRoute('ai-remaining')"
                        :route-upload="$_blogRoute('upload-image')"
                    />
                    @error('content')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div x-data="{ preview: null }">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_cover_image') }}</label>
                    @if($post->image)
                    <div class="mb-2" x-show="!preview">
                        <img src="{{ $post->image_url }}" alt="" class="h-36 rounded-lg object-cover shadow-sm">
                    </div>
                    @endif
                    <input type="file" name="image" accept="image/*" x-ref="fileInput"
                        @change="const f = $event.target.files[0]; if (f) { const r = new FileReader(); r.onload = e => preview = e.target.result; r.readAsDataURL(f); } else { preview = null; }"
                        class="w-full text-sm text-gray-500 @error('image') rounded-lg ring-1 ring-red-500 @enderror file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    @error('image')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                    <template x-if="preview">
                        <div class="mt-3 relative inline-block">
                            <img :src="preview" class="h-36 rounded-lg object-cover shadow-sm">
                            <button type="button" @click="preview = null; $refs.fileInput.value = ''"
                                class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center text-xs shadow-md hover:bg-red-600 transition">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </template>
                    @if($post->image)
                    <div class="flex items-center gap-2 mt-2">
                        <input type="checkbox" name="remove_image" id="remove_image" value="1">
                        <label for="remove_image" class="text-sm text-gray-500 dark:text-gray-400">{{ __('blog.remove_current_image') }}</label>
                    </div>
                    @endif
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('blog.label_category') }}</label>
                    <select name="category_id"
                        class="w-full px-3 py-2 border @error('category_id') border-red-500 ring-1 ring-red-500 dark:border-red-500 @else border-gray-300 dark:border-gray-600 @enderror rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                        <option value="">{{ __('blog.option_none') }}</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $post->category_id) === $cat->id ? 'selected' : '' }}>{{ $cat->displayName('blog') }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_tags') }}</label>
                    <input type="text" name="tags" value="{{ old('tags', $post->tags->pluck('name')->implode(', ')) }}"
                        placeholder="{{ __('blog.placeholder_tags') }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                </div>

                <details class="group">
                    <summary class="text-sm font-medium text-gray-500 dark:text-gray-400 cursor-pointer hover:text-gray-700 dark:hover:text-gray-200 transition">{{ __('blog.label_seo') }}</summary>
                    <div class="mt-3 space-y-3 pl-4 border-l-2 border-gray-100 dark:border-gray-700">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('blog.label_meta_title') }}</label>
                            <input type="text" name="meta_title" value="{{ old('meta_title', $post->meta_title) }}" maxlength="255"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('blog.label_meta_description') }}</label>
                            <textarea name="meta_description" rows="2" maxlength="320"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">{{ old('meta_description', $post->meta_description) }}</textarea>
                        </div>
                    </div>
                </details>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('blog.label_status') }}</label>
                    <div class="flex gap-4">
                        @foreach(['draft' => __('blog.status_draft'), 'pending' => __('blog.status_pending'), 'published' => __('blog.status_published'), 'archived' => __('blog.status_archived')] as $val => $label)
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" name="status" value="{{ $val }}" {{ old('status', $post->status) === $val ? 'checked' : '' }} class="text-indigo-600">
                            {{ $label }}
                        </label>
                        @endforeach
                    </div>
                    @error('status')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition">
                        {{ __('blog.btn_save') }}
                    </button>
                    <a href="{{ $_blogRoute('show', ['post' => $post]) }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('blog.btn_cancel') }}
                    </a>
                </div>
            </form>

            {{-- Formulaire de suppression EN DEHORS du formulaire principal --}}
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 flex justify-end">
                <form action="{{ $_blogRoute('destroy', ['post' => $post]) }}" method="POST"
                      onsubmit="return confirm('{{ __('blog.confirm_delete_post') }}')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                        {{ __('blog.btn_delete_post') }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Resize handle --}}
    <div
        @mousedown="startResize"
        class="shrink-0 w-1.5 cursor-col-resize bg-transparent hover:bg-indigo-300 dark:hover:bg-indigo-600 active:bg-indigo-400 dark:active:bg-indigo-500 transition-colors hidden md:block"
    ></div>

    {{-- Right sidebar --}}
    <aside
        :style="`width: ${width}px`"
        class="shrink-0 hidden md:flex flex-col space-y-2"
    >
        {{-- Generic cards (boucle, coecriture, annotations) --}}
        <template x-for="card in cards" :key="card.key">
            <div class="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800">
                <button
                    @click="toggle(card.key)"
                    class="flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition"
                >
                    <span x-text="card.label"></span>
                    <svg
                        class="w-3 h-3 transition-transform"
                        :class="{ 'rotate-180': card.open }"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="card.open" x-cloak class="px-3 pb-3 text-xs text-gray-500 dark:text-gray-400 max-h-40 overflow-y-auto">
                    <span x-text="card.placeholder"></span>
                </div>
            </div>
        </template>

        {{-- Snapshot card --}}
        <div
            x-data="blogSnapshotCard({
                storeUrl: @js($_blogRoute('snapshots.store', ['post' => $post])),
                indexUrl: @js($_blogRoute('snapshots.index', ['post' => $post])),
                restoreUrlBase: @js($_blogRoute('snapshots.restore', ['post' => $post, 'snapshot' => '__PLACEHOLDER__'])),
                i18n: {
                    snapshotCreated: @js(__('blog.snapshot_created')),
                    snapshotNamed: @js(__('blog.snapshot_named')),
                    snapshotLoadError: @js(__('blog.snapshot_load_error')),
                    snapshotRestoreError: @js(__('blog.snapshot_restore_error')),
                    snapshotRestored: @js(__('blog.snapshot_restored')),
                    snapshotConfirmRestore: @js(__('blog.snapshot_confirm_restore')),
                },
            })"
            class="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800"
        >
            <button
                @click="toggle()"
                class="flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition"
            >
                <span>{{ __('blog.sidebar_snapshot') }}</span>
                <svg
                    class="w-3 h-3 transition-transform"
                    :class="{ 'rotate-180': open }"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div x-show="open" x-cloak class="px-3 pb-3 space-y-3 max-h-[32rem] overflow-y-auto">
                {{-- Success message --}}
                <div x-show="success" x-cloak class="text-xs text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-2 py-1 rounded" x-text="success"></div>
                {{-- Error message --}}
                <div x-show="error" x-cloak class="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded" x-text="error"></div>

                {{-- Manual create form --}}
                <div>
                    <input type="text" x-model="name" :placeholder="@js(__('blog.snapshot_name_placeholder'))" maxlength="255"
                        class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-1 focus:ring-indigo-500 mb-1">
                    <textarea x-model="comment" :placeholder="@js(__('blog.snapshot_comment_placeholder'))" rows="2" maxlength="1000"
                        class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-1 focus:ring-indigo-500 mb-1"></textarea>
                    <button @click="createSnapshot()" :disabled="saving || !name"
                        class="w-full px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded transition">
                        <span x-show="!saving">{{ __('blog.snapshot_btn_create') }}</span>
                        <span x-show="saving" class="flex items-center justify-center gap-1">
                            <svg class="animate-spin h-3 w-3" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            {{ __('blog.snapshot_btn_create') }}
                        </span>
                    </button>
                </div>

                {{-- Inline history --}}
                <template x-if="loading && snapshots.length === 0">
                    <div class="flex items-center justify-center py-4">
                        <svg class="animate-spin h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    </div>
                </template>

                <template x-if="!loading && snapshots.length === 0">
                    <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2">{{ __('blog.snapshot_history_empty') }}</p>
                </template>

                <template x-if="selectedSnapshot()">
                    <div class="rounded-xl border border-indigo-100 bg-indigo-50/60 p-3 dark:border-indigo-900/60 dark:bg-indigo-950/20">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">{{ __('blog.snapshot_preview') }}</p>
                                <h3 class="mt-0.5 truncate text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="selectedSnapshot().name"></h3>
                                <p class="text-[10px] text-gray-500 dark:text-gray-400" x-text="selectedSnapshot().created_at + (selectedSnapshot().creator_name ? ' · ' + selectedSnapshot().creator_name : '')"></p>
                            </div>
                            <button type="button" @click="restoreSnapshot(selectedSnapshot().id)"
                                class="shrink-0 rounded-md bg-indigo-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                :disabled="loading">
                                {{ __('blog.snapshot_restore_btn') }}
                            </button>
                        </div>

                        <div class="mt-2 space-y-1 rounded-lg bg-white/80 p-2 dark:bg-gray-900/70">
                            <p class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100" x-text="selectedSnapshot().title"></p>
                            <template x-if="selectedSnapshot().summary">
                                <p class="text-[10px] text-gray-600 dark:text-gray-300" x-text="selectedSnapshot().summary"></p>
                            </template>
                            <p class="line-clamp-4 text-[10px] leading-4 text-gray-500 dark:text-gray-400" x-text="previewText(selectedSnapshot()) || '{{ __('blog.snapshot_preview_empty') }}'"></p>
                            <template x-if="selectedSnapshot().comment">
                                <p class="border-t border-gray-100 pt-1 text-[10px] italic text-gray-500 dark:border-gray-700 dark:text-gray-400" x-text="'— ' + selectedSnapshot().comment"></p>
                            </template>
                        </div>

                        <div class="mt-2 rounded-lg border border-gray-100 bg-white/90 p-2 dark:border-gray-700 dark:bg-gray-900/80">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('blog.snapshot_diff_title') }}</p>
                                    <template x-if="canCompare()">
                                        <p class="truncate text-[10px] text-gray-500 dark:text-gray-400" x-text="'{{ __('blog.snapshot_compare_previous') }}'.replace(':name', comparisonSnapshot().name)"></p>
                                    </template>
                                </div>
                            </div>

                            <template x-if="!canCompare()">
                                <p class="mt-2 rounded-md bg-gray-50 px-2 py-1 text-[10px] text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ __('blog.snapshot_no_previous_loaded') }}</p>
                            </template>

                            <template x-if="canCompare()">
                                <div class="mt-2 space-y-2">
                                    <div>
                                        <p class="mb-1 text-[10px] font-semibold text-gray-500 dark:text-gray-400">{{ __('blog.snapshot_changed_fields') }}</p>
                                        <template x-if="changedFields().length === 0">
                                            <span class="inline-flex rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ __('blog.snapshot_unchanged') }}</span>
                                        </template>
                                        <div x-show="changedFields().length > 0" class="flex flex-wrap gap-1">
                                            <template x-for="field in changedFields()" :key="field">
                                                <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-200"
                                                    x-text="({ title: @js(__('blog.label_title')), summary: @js(__('blog.label_summary')), status: @js(__('blog.label_status')), meta_title: @js(__('blog.label_meta_title')), meta_description: @js(__('blog.label_meta_description')) })[field] || field"></span>
                                            </template>
                                        </div>
                                    </div>

                                    <div class="max-h-28 overflow-y-auto rounded-md bg-gray-50 p-2 text-[10px] leading-5 dark:bg-gray-950/60">
                                        <template x-for="(part, index) in diffText(selectedSnapshot(), comparisonSnapshot())" :key="index">
                                            <span>
                                                <span x-show="part.type === 'added'" class="rounded bg-green-100 px-1 py-0.5 font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-200" x-text="'{{ __('blog.snapshot_added') }} + ' + part.text"></span>
                                                <span x-show="part.type === 'removed'" class="rounded bg-red-100 px-1 py-0.5 font-semibold text-red-800 dark:bg-red-900/40 dark:text-red-200" x-text="'{{ __('blog.snapshot_removed') }} - ' + part.text"></span>
                                                <span x-show="part.type === 'unchanged'" class="text-gray-500 dark:text-gray-400" x-text="part.text"></span>
                                                <span> </span>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="mt-2 flex items-center justify-between gap-2">
                            <button type="button" @click="selectPrevious()" :disabled="!canGoPrevious()"
                                class="rounded-md border border-gray-200 px-2 py-1 text-[10px] font-semibold text-gray-600 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-900">
                                {{ __('blog.snapshot_previous') }}
                            </button>
                            <span class="text-[10px] text-gray-500 dark:text-gray-400" x-text="'{{ __('blog.snapshot_position') }}'.replace(':current', selectedIndex() + 1).replace(':total', snapshots.length)"></span>
                            <button type="button" @click="selectNext()" :disabled="!canGoNext()"
                                class="rounded-md border border-gray-200 px-2 py-1 text-[10px] font-semibold text-gray-600 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-900">
                                {{ __('blog.snapshot_next') }}
                            </button>
                        </div>
                    </div>
                </template>

                <template x-if="snapshots.length > 0">
                    <div class="rounded-lg border border-gray-100 bg-white p-2 dark:border-gray-700 dark:bg-gray-900">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('blog.snapshot_loaded_versions') }}</p>
                        <div class="flex gap-1 overflow-x-auto pb-1">
                            <template x-for="s in snapshots" :key="s.id">
                                <button type="button" @click="selectSnapshot(s.id)"
                                    :title="s.name"
                                    :class="selectedSnapshot()?.id === s.id ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-500 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'"
                                    class="h-2.5 min-w-8 rounded-full transition">
                                    <span class="sr-only" x-text="s.name"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-for="s in snapshots" :key="s.id">
                    <button type="button" @click="selectSnapshot(s.id)"
                        :class="selectedSnapshot()?.id === s.id ? 'border-indigo-200 bg-indigo-50 dark:border-indigo-800 dark:bg-indigo-950/30' : 'border-gray-100 bg-white hover:border-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600'"
                        class="block w-full rounded-lg border p-2 text-left transition">
                        <div class="flex items-start justify-between gap-1">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100" x-text="s.name"></p>
                                <p class="text-[10px] text-gray-500 dark:text-gray-400" x-text="s.created_at + (s.creator_name ? ' · ' + s.creator_name : '')"></p>
                            </div>
                            <span x-show="selectedSnapshot()?.id === s.id" class="shrink-0 rounded-full bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-200">{{ __('blog.snapshot_selected') }}</span>
                        </div>
                        <template x-if="s.comment">
                            <p class="mt-1 truncate text-[10px] italic text-gray-500 dark:text-gray-400" x-text="'— ' + s.comment"></p>
                        </template>
                        <template x-if="s.is_restored">
                            <p class="mt-1 inline-flex rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ __('blog.snapshot_restored_label') }}</p>
                        </template>
                    </button>
                </template>

                <button type="button" x-show="hasMore" @click="loadMore()" :disabled="loading"
                    class="w-full rounded-md border border-dashed border-gray-300 dark:border-gray-600 px-2 py-1 text-[10px] font-semibold text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <span x-show="!loading">
                        <span x-text="'{{ __('blog.snapshot_load_more') }}'.replace(':remaining', remainingCount())"></span>
                    </span>
                    <span x-show="loading" class="flex items-center justify-center gap-1">
                        <svg class="animate-spin h-3 w-3" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        {{ __('blog.snapshot_loading') }}
                    </span>
                </button>
            </div>
        </div>
        {{-- /Snapshot card --}}
    </aside>

    </div>
</div>
</x-app-layout>
