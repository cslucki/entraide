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

    @php
        $backRouteName = $post->status === 'published' ? 'show' : 'my-posts';
        $backLabel = $post->status === 'published' ? __('blog.back_to_article') : __('blog.back_to_my_articles');
    @endphp

    <div class="max-w-7xl mx-auto px-4 py-8">

        <div class="mb-6">
            <a href="{{ $_blogRoute($backRouteName, ['post' => $post]) }}" class="text-sm text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400">← {{ $backLabel }}</a>
        </div>

        {{-- Editor layout: main panel + resize handle + sidebar --}}
        <div class="flex flex-col md:flex-row gap-0"
             x-data="{
                cards: [
                    { key: 'boucle', label: @js(__('blog.sidebar_boucle')), open: false, placeholder: @js(__('blog.sidebar_boucle_placeholder')) },
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
                        :route-annotation-store="$_blogRoute('annotations.store', ['post' => $post])"
                        :route-annotation-content-save="$_blogRoute('save-content', ['post' => $post])"
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

                <div x-data="{
                    tags: '{{ old('tags', $post->tags->pluck('name')->implode(', ')) }}',
                    tagList: [],
                    tagInput: '',
                    addTag() {
                        let t = this.tagInput.trim();
                        if (!t || this.tagList.length >= 10) return;
                        this.tagList.push(t);
                        this.tags = this.tagList.join(',');
                        this.tagInput = '';
                    },
                    removeTag(i) {
                        this.tagList.splice(i, 1);
                        this.tags = this.tagList.join(',');
                    },
                    init() {
                        if (this.tags) this.tagList = this.tags.split(',').map(t => t.trim()).filter(t => t);
                    }
                }">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_tags') }} <span class="text-gray-400">{{ __('blog.tags_max_10') }}</span></label>
                    <input type="hidden" name="tags" x-bind:value="tags">
                    <div class="flex flex-wrap gap-2 mb-2">
                        <template x-for="(tag, i) in tagList" :key="i">
                            <span class="flex items-center gap-1 px-2 py-0.5 bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 rounded text-sm">
                                <span x-text="tag"></span>
                                <button type="button" @click="removeTag(i)" class="ml-1 text-indigo-400 hover:text-indigo-700">&times;</button>
                            </span>
                        </template>
                    </div>
                    <div class="flex gap-2" x-show="tagList.length < 10">
                        <input type="text" x-model="tagInput" @keydown.enter.prevent="addTag" placeholder="{{ __('blog.add_tag_placeholder') }}"
                            class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <button type="button" @click="addTag" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300">{{ __('blog.add_tag') }}</button>
                    </div>
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
                    <a href="{{ $_blogRoute($backRouteName, ['post' => $post]) }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('blog.btn_cancel') }}
                    </a>
                </div>
            </form>

            @can('delete', $post)
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
            @endcan
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
        class="flex w-full shrink-0 flex-col space-y-2 md:w-auto"
    >
                {{-- Generic cards (boucle) --}}
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

                {{-- Annotations card --}}
                <div
                    x-data="blogAnnotationCard({
                        indexUrl: @js($_blogRoute('annotations.index', ['post' => $post])),
                        updateUrlBase: @js($_blogRoute('annotations.update', ['post' => $post, 'annotation' => '__ANNOTATION_ID__'])),
                        destroyUrlBase: @js($_blogRoute('annotations.destroy', ['post' => $post, 'annotation' => '__ANNOTATION_ID__'])),
                        resolveUrlBase: @js($_blogRoute('annotations.resolve', ['post' => $post, 'annotation' => '__ANNOTATION_ID__'])),
                        replyStoreUrlBase: @js($_blogRoute('annotations.replies.store', ['post' => $post, 'annotation' => '__ANNOTATION_ID__'])),
                        replyUpdateUrlBase: @js($_blogRoute('annotations.replies.update', ['post' => $post, 'annotation' => '__ANNOTATION_ID__', 'reply' => '__REPLY_ID__'])),
                        replyDestroyUrlBase: @js($_blogRoute('annotations.replies.destroy', ['post' => $post, 'annotation' => '__ANNOTATION_ID__', 'reply' => '__REPLY_ID__'])),
                        i18n: {
                            openTab: @js(__('blog.open_annotations')),
                            resolvedTab: @js(__('blog.resolved_annotations')),
                            save: @js(__('blog.save')),
                            cancel: @js(__('blog.btn_cancel')),
                            edit: @js(__('blog.edit_annotation')),
                            delete: @js(__('blog.delete_annotation')),
                            resolve: @js(__('blog.resolve_annotation')),
                            resolved: @js(__('blog.annotation_resolved')),
                            deleted: @js(__('blog.annotation_deleted')),
                            updated: @js(__('blog.annotation_updated')),
                            noOpen: @js(__('blog.no_open_annotations')),
                            noResolved: @js(__('blog.no_resolved_annotations')),
                            confirmDelete: @js(__('blog.confirm_delete_annotation')),
                            badgeDeleted: @js(__('blog.annotation_badge_deleted')),
                            textDeleted: @js(__('blog.annotation_text_deleted')),
                            badgeStale: @js(__('blog.annotation_badge_stale')),
                            textStale: @js(__('blog.annotation_text_stale')),
                            refreshDoc: @js(__('blog.annotation_refresh_doc')),
                            csrfToken: @js(csrf_token()),
                        },
                    })"
                    class="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800"
                >
                    <button
                        @click="toggle()"
                        class="bp-annotation-card-header flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition"
                    >
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-indigo-500 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                            <span>{{ __('blog.sidebar_annotations') }}</span>
                        </span>
                        <svg
                            class="w-3 h-3 transition-transform"
                            :class="{ 'rotate-180': isOpen }"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="isOpen" x-cloak class="px-3 pb-3 space-y-2 max-h-[min(34rem,calc(100vh-8rem))] overflow-y-auto">
                        {{-- Success / Error --}}
                        <div x-show="success" x-cloak class="text-xs text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-2 py-1 rounded" x-text="success"></div>
                        <div x-show="error" x-cloak class="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded" x-text="error"></div>

                        {{-- Filter tabs --}}
                        <div class="flex gap-1 border-b border-gray-100 dark:border-gray-700 pb-1">
                            <button type="button" @click="filterTab = 'open'"
                                :class="filterTab === 'open' ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                class="px-2 py-1 text-[10px] font-semibold rounded transition"
                                x-text="i18n.openTab"></button>
                            <button type="button" @click="filterTab = 'resolved'"
                                :class="filterTab === 'resolved' ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                class="px-2 py-1 text-[10px] font-semibold rounded transition"
                                x-text="i18n.resolvedTab"></button>
                        </div>

                        {{-- Loading --}}
                        <template x-if="loading && annotations.length === 0">
                            <div class="flex items-center justify-center py-4">
                                <svg class="animate-spin h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            </div>
                        </template>

                        {{-- Empty states --}}
                        <template x-if="!loading && filteredAnnotations.length === 0 && filterTab === 'open'">
                            <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2" x-text="i18n.noOpen"></p>
                        </template>
                        <template x-if="!loading && filteredAnnotations.length === 0 && filterTab === 'resolved'">
                            <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2" x-text="i18n.noResolved"></p>
                        </template>

                        {{-- Annotation list --}}
                        <template x-for="a in filteredAnnotations" :key="a.id">
                            <div :data-annotation-card-id="a.id"
                                class="rounded-lg border border-gray-100 bg-gray-50/70 p-2 dark:border-gray-700 dark:bg-gray-900/50 cursor-pointer hover:border-indigo-200 dark:hover:border-indigo-800 transition"
                                :class="{ 'ring-2 ring-indigo-400 border-indigo-300 dark:ring-indigo-500 dark:border-indigo-600': selectedAnnotationId === a.id }"
                                @click="selectAnnotation(a.id)">
                                <div class="space-y-1">
                                        <span x-show="a._orphaned"
                                            class="inline-flex items-center gap-1 text-[9px] font-semibold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded uppercase tracking-wider leading-none">
                                            <span x-text="i18n.badgeStale"></span>
                                            <button type="button" @click.stop="refreshDocument()"
                                                class="underline hover:text-amber-900 dark:hover:text-amber-100"
                                                x-text="i18n.refreshDoc"></button>
                                        </span>
                                        <p class="text-[10px] italic truncate"
                                            :class="a._orphaned ? 'text-gray-400 dark:text-gray-500 line-through' : 'text-gray-500 dark:text-gray-400'"
                                            x-text="'&ldquo;' + a.selected_text + '&rdquo;'"></p>
                                        <p class="text-xs text-gray-800 dark:text-gray-100" x-text="a.content"></p>
                                        <p class="text-[10px] text-gray-400 dark:text-gray-500">
                                            <span x-text="a.author_name"></span>
                                            <span> · </span>
                                            <span x-text="a.created_at_human"></span>
                                            <template x-if="a.status === 'resolved' && a.resolved_at">
                                                <span> · {{ __('blog.resolved') }}</span>
                                            </template>
                                        </p>
                                        <div class="flex gap-2 mt-1" @click.stop>
                                            <button type="button" x-show="a.can_edit"
                                                @click="editAnnotation(a)"
                                                class="text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                                                x-text="i18n.edit"></button>
                                            <button type="button" x-show="a.can_delete"
                                                @click="askDeleteAnnotation(a.id)"
                                                class="text-[10px] font-semibold text-red-600 dark:text-red-400 hover:underline"
                                                x-text="i18n.delete"></button>
                                            <button type="button" x-show="a.can_resolve && a.status === 'open'"
                                                @click="resolveAnnotation(a.id)"
                                                class="text-[10px] font-semibold text-green-600 dark:text-green-400 hover:underline"
                                                x-text="i18n.resolve"></button>
                                        </div>
                                        <template x-if="a.resolved_by_name && a.status === 'resolved'">
                                            <p class="text-[10px] text-gray-400 dark:text-gray-500">
                                                {{ __('blog.resolved_by') }} <span x-text="a.resolved_by_name"></span>
                                            </p>
                                        </template>

                                        <template x-if="pendingDeleteAnnotationId === a.id">
                                            <div @click.stop class="mt-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded">
                                                <p class="text-[10px] text-red-700 dark:text-red-300" x-text="i18n.confirmDelete"></p>
                                                <div class="flex gap-1.5 mt-1">
                                                    <button type="button" @click="confirmDeleteAnnotation()"
                                                        class="px-1.5 py-0.5 text-[10px] font-semibold text-white bg-red-600 hover:bg-red-700 rounded transition"
                                                        x-text="i18n.delete"></button>
                                                    <button type="button" @click="cancelDeleteAnnotation()"
                                                        class="px-1.5 py-0.5 text-[10px] font-semibold text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition"
                                                        x-text="i18n.cancel"></button>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Replies section --}}
                                        <div class="mt-2 border-t border-gray-100 dark:border-gray-700 pt-2 space-y-1.5" @click.stop>
                                            <template x-for="r in (a.replies || [])" :key="r.id">
                                                <div class="rounded bg-white dark:bg-gray-800 px-2 py-1.5 text-xs border border-gray-50 dark:border-gray-700">
                                                    <template x-if="replyEditingId === r.id">
                                                        <div class="space-y-1">
                                                            <textarea x-model="replyEditContent" rows="2" maxlength="5000"
                                                                class="w-full px-2 py-1 text-[10px] border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-1 focus:ring-indigo-500"></textarea>
                                                            <div class="flex gap-1.5">
                                                                <button type="button" @click="updateReply(a.id, r.id)" :disabled="replySaving || !replyEditContent.trim()"
                                                                    class="px-1.5 py-0.5 text-[10px] font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded transition"
                                                                    x-text="'{{ __('blog.save') }}'"></button>
                                                                <button type="button" @click="cancelReplyEdit()"
                                                                    class="px-1.5 py-0.5 text-[10px] font-semibold text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition"
                                                                    x-text="i18n.cancel"></button>
                                                            </div>
                                                        </div>
                                                    </template>
                                                    <template x-if="replyEditingId !== r.id">
                                                        <div>
                                                            <p class="text-[10px] text-gray-800 dark:text-gray-100" x-text="r.content"></p>
                                                            <div class="flex items-center gap-1.5 mt-0.5">
                                                                <span class="text-[9px] text-gray-400 dark:text-gray-500" x-text="r.author_name + ' · ' + r.created_at_human"></span>
                                                                <button type="button" x-show="r.can_edit"
                                                                    @click="editReply(r)"
                                                                    class="text-[9px] font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                                                                    x-text="'{{ __('blog.annotation_reply_edit') }}'"></button>
                                                                <button type="button" x-show="r.can_delete"
                                                                    @click="askDeleteReply(a.id, r.id)"
                                                                    class="text-[9px] font-semibold text-red-600 dark:text-red-400 hover:underline"
                                                                    x-text="'{{ __('blog.annotation_reply_delete') }}'"></button>
                                                            </div>
                                                            <template x-if="pendingDeleteReplyId === r.id">
                                                                <div class="mt-1 p-1.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded">
                                                                    <p class="text-[9px] text-red-700 dark:text-red-300">{{ __('blog.annotation_reply_confirm_delete') }}</p>
                                                                    <div class="flex gap-1 mt-1">
                                                                        <button type="button" @click="confirmDeleteReply()"
                                                                            class="px-1 py-0.5 text-[9px] font-semibold text-white bg-red-600 hover:bg-red-700 rounded transition"
                                                                            x-text="'{{ __('blog.annotation_reply_delete') }}'"></button>
                                                                        <button type="button" @click="cancelDeleteReply()"
                                                                            class="px-1 py-0.5 text-[9px] font-semibold text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition"
                                                                            x-text="i18n.cancel"></button>
                                                                    </div>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                            <template x-if="!(a.replies && a.replies.length)">
                                                <p class="text-[10px] text-gray-400 dark:text-gray-500 italic" x-text="'{{ __('blog.annotation_reply_empty') }}'"></p>
                                            </template>
                                            <div class="flex gap-1.5">
                                                <input type="text" x-model="replyContents[a.id]"
                                                    :placeholder="'{{ __('blog.annotation_reply_placeholder') }}'"
                                                    maxlength="5000"
                                                    @keydown.enter.prevent="submitReply(a.id)"
                                                    class="flex-1 px-2 py-1 text-[10px] border border-gray-200 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-1 focus:ring-indigo-500"
                                                    :disabled="replySaving">
                                                <button type="button" @click="submitReply(a.id)" :disabled="replySaving || !(replyContents[a.id] || '').trim()"
                                                    class="shrink-0 px-2 py-1 text-[10px] font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded transition"
                                                    x-text="replySaving ? '{{ __('blog.annotation_reply_saving') }}' : '{{ __('blog.annotation_reply_btn') }}'"></button>
                                            </div>
                                        </div>

                                        <p x-show="deletedFeedbackAnnotationId === a.id"
                                            x-cloak
                                            class="text-[10px] text-amber-600 dark:text-amber-400"
                                            x-text="i18n.textStale"></p>
                                    </div>
                            </div>
                        </template>

                        {{-- Loading more --}}
                        <div x-show="loading && annotations.length > 0" class="flex justify-center py-2">
                            <svg class="animate-spin h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        </div>
                    </div>
                </div>
                {{-- /Annotations card --}}

                {{-- Blue accent on annotation card header --}}
    <style>
    .bp-annotation-card-header {
        border-left: 3px solid #6366f1;
    }
    .dark .bp-annotation-card-header {
        border-left-color: #818cf8;
    }
    </style>

    {{-- Annotation creation modal --}}
    <div
        x-data="annotationModal"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4 md:translate-y-0 md:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 md:scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 md:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 md:translate-y-0 md:scale-95"
        class="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40"
        @keydown.escape.window="cancel()"
    >
        <div class="bg-white dark:bg-gray-800 rounded-t-2xl md:rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 w-full md:max-w-lg md:mx-4 max-h-[85dvh] md:max-h-none overflow-y-auto" @click.stop>
            <div class="p-5 space-y-4">
                <h3 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                    </svg>
                    <span x-text="mode === 'edit' ? '{{ __('blog.edit_annotation') }}' : '{{ __('blog.add_annotation') }}'"></span>
                </h3>

                <div class="rounded-lg bg-indigo-50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/60 p-3">
                    <p class="text-[11px] font-medium text-indigo-700 dark:text-indigo-300 mb-1">{{ __('blog.annotation_selected_text') }}</p>
                    <p class="text-sm text-gray-700 dark:text-gray-300 italic leading-relaxed" x-text="'&ldquo;' + selectedText + '&rdquo;'"></p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('blog.annotation_your_note') }}</label>
                    <textarea x-model="content"
                        placeholder="{{ __('blog.annotation_placeholder') }}"
                        rows="3" maxlength="5000"
                        class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 resize-none"
                        :disabled="saving"
                    ></textarea>
                </div>

                <div x-show="error" x-cloak class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 px-3 py-2 rounded-lg" x-text="error"></div>

                <div class="flex items-center justify-end gap-3 pt-1">
                    <button type="button" @click="cancel()" :disabled="saving"
                        class="px-4 py-2 text-sm font-semibold text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        x-text="'{{ __('blog.btn_cancel') }}'"></button>
                    <button type="button" @click="save()" :disabled="saving || !content.trim()"
                        class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition inline-flex items-center gap-2">
                        <svg x-show="saving" class="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span x-text="saving ? '{{ __('blog.annotation_saving') }}' : '{{ __('blog.save') }}'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Co-authors card --}}
        <div
            x-data="blogCoAuthorCard({
                indexUrl: @js($_blogRoute('co-authors.index', ['post' => $post])),
                storeUrl: @js($_blogRoute('co-authors.store', ['post' => $post])),
                destroyUrlBase: @js($_blogRoute('co-authors.destroy', ['post' => $post, 'user' => '__USER_ID__'])),
                searchUrl: @js($_blogRoute('co-authors.search', ['post' => $post])),
                isOwner: {{ Auth::id() === $post->user_id ? 'true' : 'false' }},
                isAdmin: {{ Auth::user()->is_admin ? 'true' : 'false' }},
                postOwnerId: @js($post->user_id),
                i18n: {
                    empty: @js(__('blog.co_author_empty')),
                    hint: @js(__('blog.co_author_hint')),
                    add: @js(__('blog.co_author_add')),
                    remove: @js(__('blog.co_author_remove')),
                    confirmRemove: @js(__('blog.co_author_remove_confirm')),
                    selectPlaceholder: @js(__('blog.co_author_select_placeholder')),
                    added: @js(__('blog.co_author_added')),
                    removed: @js(__('blog.co_author_removed')),
                    loadError: @js(__('blog.co_author_load_error')),
                    addError: @js(__('blog.co_author_add_error')),
                    removeError: @js(__('blog.co_author_remove_error')),
                    you: @js(__('blog.co_author_manage_you')),
                    csrfToken: @js(csrf_token()),
                },
            })"
            class="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800"
        >
            <button
                @click="toggle()"
                class="flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition"
            >
                <span>{{ __('blog.sidebar_co_ecriture') }}</span>
                <svg
                    class="w-3 h-3 transition-transform"
                    :class="{ 'rotate-180': open }"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div x-show="open" x-cloak class="px-3 pb-3 space-y-3 max-h-[min(34rem,calc(100vh-8rem))] overflow-y-auto">
                <div x-show="success" x-cloak class="text-xs text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-2 py-1 rounded" x-text="success"></div>
                <div x-show="error" x-cloak class="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded" x-text="error"></div>

                <template x-if="!loading && coAuthors.length === 0">
                    <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2" x-text="i18n.empty"></p>
                </template>

                <template x-if="loading">
                    <div class="flex items-center justify-center py-4">
                        <svg class="animate-spin h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    </div>
                </template>

                <template x-for="author in coAuthors" :key="author.id">
                    <div class="flex items-center justify-between gap-2 rounded-lg border border-gray-100 bg-gray-50/70 p-2 dark:border-gray-700 dark:bg-gray-900/50">
                        <div class="flex items-center gap-2 min-w-0">
                            <img :src="author.avatar_url" :alt="author.name" class="w-6 h-6 rounded-full shrink-0">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100" x-text="author.name"></p>
                            </div>
                        </div>
                        <button
                            x-show="canManage() && author.id !== postOwnerId"
                            type="button"
                            @click="removeCoAuthor(author.id)"
                            :disabled="removing"
                            class="shrink-0 rounded-md px-2 py-1 text-[10px] font-semibold text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20 transition disabled:opacity-50"
                            x-text="i18n.remove"
                        ></button>
                    </div>
                </template>

                <div x-show="canManage()" class="border-t border-gray-100 dark:border-gray-700 pt-3">
                    <div class="flex gap-2">
                        <input type="text" x-model="userQuery" @input.debounce="searchUsers()" :placeholder="i18n.selectPlaceholder"
                            class="flex-1 px-2 py-1.5 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-1 focus:ring-indigo-500">
                        <button type="button" @click="addCoAuthor()" :disabled="adding || !selectedUserId"
                            class="shrink-0 px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded transition">
                            <span x-text="i18n.add"></span>
                        </button>
                    </div>
                    <div x-show="searchResults.length > 0" class="mt-1 rounded-lg border border-gray-100 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 max-h-28 overflow-y-auto">
                        <template x-for="u in searchResults" :key="u.id">
                            <button type="button" @click="selectUser(u)"
                                class="flex items-center gap-2 w-full px-2 py-1.5 text-xs text-left hover:bg-gray-50 dark:hover:bg-gray-700 transition"
                            >
                                <img :src="u.avatar_url" :alt="u.name" class="w-5 h-5 rounded-full">
                                <span x-text="u.name" class="text-gray-800 dark:text-gray-100"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <p class="text-[10px] leading-4 text-gray-400 dark:text-gray-500" x-text="i18n.hint"></p>
            </div>
        </div>
        {{-- /Co-authors card --}}

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

            <div x-show="open" x-cloak class="px-3 pb-3 space-y-3 max-h-[min(34rem,calc(100vh-8rem))] overflow-y-auto">
                {{-- Success message --}}
                <div x-show="success" x-cloak class="text-xs text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-2 py-1 rounded" x-text="success"></div>
                {{-- Error message --}}
                <div x-show="error" x-cloak class="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded" x-text="error"></div>

                {{-- Manual create form --}}
                <div class="rounded-lg border border-gray-100 bg-gray-50/70 p-2 dark:border-gray-700 dark:bg-gray-900/50">
                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('blog.snapshot_manual_title') }}</p>
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
                    <p class="mt-1 text-[10px] leading-4 text-gray-400 dark:text-gray-500">{{ __('blog.snapshot_manual_hint') }}</p>
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
                    <div class="rounded-xl border border-indigo-100 bg-indigo-50/40 p-3 shadow-sm dark:border-indigo-900/60 dark:bg-indigo-950/10">
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

                        <div class="mt-2 space-y-1 rounded-lg bg-white/85 p-2 dark:bg-gray-900/70">
                            <p class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100" x-text="selectedSnapshot().title"></p>
                            <template x-if="selectedSnapshot().summary">
                                <p class="text-[10px] text-gray-600 dark:text-gray-300" x-text="selectedSnapshot().summary"></p>
                            </template>
                            <p class="line-clamp-4 text-[10px] leading-4 text-gray-500 dark:text-gray-400" x-text="previewText(selectedSnapshot()) || '{{ __('blog.snapshot_preview_empty') }}'"></p>
                            <template x-if="selectedSnapshot().comment">
                                <p class="border-t border-gray-100 pt-1 text-[10px] italic text-gray-500 dark:border-gray-700 dark:text-gray-400" x-text="'— ' + selectedSnapshot().comment"></p>
                            </template>
                        </div>

                        <div class="mt-2 rounded-lg border border-gray-100 bg-gray-50/80 p-2 dark:border-gray-700 dark:bg-gray-900/70">
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

                                    <div class="max-h-24 overflow-y-auto rounded-md bg-white p-2 text-[10px] leading-5 dark:bg-gray-950/60">
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
                    <div class="rounded-lg border border-gray-100 bg-gray-50/80 p-2 dark:border-gray-700 dark:bg-gray-900/70">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('blog.snapshot_loaded_versions') }}</p>
                        <div class="flex gap-1 overflow-x-auto pb-1">
                            <template x-for="s in snapshots" :key="s.id">
                                <button type="button" @click="selectSnapshot(s.id)"
                                    :title="s.name"
                                    :class="selectedSnapshot()?.id === s.id ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-500 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'"
                                    class="h-2 min-w-7 rounded-full transition">
                                    <span class="sr-only" x-text="s.name"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-for="s in snapshots" :key="s.id">
                    <button type="button" @click="selectSnapshot(s.id)"
                        :class="selectedSnapshot()?.id === s.id ? 'border-indigo-200 bg-indigo-50 dark:border-indigo-800 dark:bg-indigo-950/30' : 'border-gray-100 bg-white hover:border-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-gray-600'"
                        class="block w-full rounded-lg border p-2 text-left transition focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
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
