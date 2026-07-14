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
    @push('scripts')
        @vite(['resources/js/deep-chat-init.js'])
    @endpush
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
                 cards: [],
                 width: 280,
                 isDesktop: window.matchMedia('(min-width: 768px)').matches,
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
                     const media = window.matchMedia('(min-width: 768px)');
                     const syncDesktop = () => { this.isDesktop = media.matches; };
                     syncDesktop();
                     media.addEventListener('change', syncDesktop);

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

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 sm:p-6">
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

                @if($post->status === 'published' || $post->published_at)
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_published_at') }}</label>
                    <input type="datetime-local" name="published_at" value="{{ old('published_at', $post->published_at ? $post->published_at->format('Y-m-d\TH:i') : '') }}"
                        class="w-full px-3 py-2 border @error('published_at') border-red-500 ring-1 ring-red-500 dark:border-red-500 @else border-gray-300 dark:border-gray-600 @enderror rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                    @error('published_at')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('blog.published_at_help') }}</p>
                </div>
                @endif

                <section x-data="{ showToc: {{ old('show_toc', $post->show_toc) ? 'true' : 'false' }} }" class="rounded-xl border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('blog.toc_section_title') }}</h2>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('blog.toc_section_help') }}</p>
                        </div>
                        <label class="mt-2 inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm text-gray-700 shadow-sm ring-1 ring-indigo-100 dark:bg-gray-900/60 dark:text-gray-200 dark:ring-indigo-900/70 sm:mt-0">
                            <input type="checkbox" name="show_toc" value="1" @change="showToc = $event.target.checked" {{ old('show_toc', $post->show_toc) ? 'checked' : '' }} class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600">
                            <span>{{ __('blog.toc_use') }}</span>
                        </label>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="toc_max_level" class="block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('blog.toc_detail_level') }}</label>
                            <select id="toc_max_level" name="toc_max_level" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                @foreach([2 => __('blog.toc_level_h2'), 3 => __('blog.toc_level_h2_h3'), 4 => __('blog.toc_level_h2_h3_h4')] as $level => $label)
                                    <option value="{{ $level }}" {{ (int) old('toc_max_level', $post->toc_max_level ?? 4) === $level ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('toc_max_level')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                        </div>

                        <div class="flex items-end">
                            <label :class="!showToc && 'opacity-40 cursor-not-allowed'" class="flex w-full items-start gap-3 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-200">
                                <input type="checkbox" name="toc_navigation_enabled" value="1" :disabled="!showToc" {{ old('toc_navigation_enabled', $post->toc_navigation_enabled) ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600">
                                <span>
                                    <span class="block font-medium">{{ __('blog.toc_navigation_enabled') }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __('blog.toc_navigation_help') }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </section>

                <div class="grid grid-cols-2 gap-2 pt-2 sm:flex sm:items-center sm:gap-3">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-center text-xs font-semibold leading-snug text-white transition hover:bg-indigo-700 sm:px-6 sm:text-sm">
                        <span class="sm:hidden">{{ __('blog.save') }}</span>
                        <span class="hidden sm:inline">{{ __('blog.btn_save') }}</span>
                    </button>
                    <a href="{{ $_blogRoute($backRouteName, ['post' => $post]) }}" class="rounded-lg border border-gray-300 px-3 py-2 text-center text-xs leading-snug text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 sm:px-6 sm:text-sm">
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
        :style="isDesktop ? `width: ${width}px` : ''"
        class="flex w-full shrink-0 flex-col space-y-2 md:w-auto"
    >
                {{-- Boucle card --}}
                <div
                    x-data="blogLoopCard({
                        storeUrl: @js($_blogRoute('loops.store', ['post' => $post])),
                        destroyUrlBase: @js($_blogRoute('loops.destroy', ['post' => $post, 'loop' => '__LOOP_ID__'])),
                        messagesUrl: @js($_blogRoute('loops.messages', ['post' => $post])),
                        storeMessageUrlBase: @js($_blogRoute('loops.messages.store', ['post' => $post, 'loop' => '__LOOP_ID__'])),
                        userLoops: @js($userLoops->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'slug' => $l->slug])->values()->toArray()),
                        linkedLoops: @js($postLoops->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'slug' => $l->slug])->values()->toArray()),
                        i18n: {
                            noLoops: @js(__('blog.loop_no_loops')),
                            noLinked: @js(__('blog.loop_no_linked_loops')),
                            selectPlaceholder: @js(__('blog.loop_select')),
                            link: @js(__('blog.loop_link')),
                            unlink: @js(__('blog.loop_unlink')),
                            noMessages: @js(__('blog.loop_no_messages')),
                            viewDiscussion: @js(__('blog.loop_view_discussion')),
                            linked: @js(__('blog.loop_linked')),
                            unlinked: @js(__('blog.loop_unlinked')),
                            csrfToken: @js(csrf_token()),
                            messagePlaceholder: @js(__('blog.loop_message_placeholder')),
                            messageSend: @js(__('blog.loop_message_send')),
                            messageSending: @js(__('blog.loop_message_sending')),
                            messageReadonly: @js(__('blog.loop_message_readonly')),
                        },
                    })"
                    class="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800"
                >
                    <button
                        @click="toggle()"
                        class="flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition"
                    >
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-red-500 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            <span>{{ __('blog.sidebar_boucle') }}</span>
                        </span>
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

                        {{-- Linked loops list --}}
                        <template x-if="!loading && linkedLoops.length === 0 && userLoops.length === 0">
                            <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2" x-text="i18n.noLoops"></p>
                        </template>

                        <template x-for="loop in linkedLoops" :key="loop.id">
                            <div class="rounded-lg border border-indigo-100 bg-indigo-50/60 p-2 dark:border-indigo-900/60 dark:bg-indigo-950/10">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100" x-text="loop.name"></span>
                                    <button type="button" @click="unlinkLoop(loop.id)"
                                        class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20 transition disabled:opacity-50"
                                        :disabled="saving">
                                        <span x-text="i18n.unlink"></span>
                                    </button>
                                </div>
                                {{-- Recent messages --}}
                                <template x-if="loop.messages && loop.messages.length">
                                    <div class="mt-1.5 space-y-1">
                                        <template x-for="msg in loop.messages" :key="msg.id">
                                            <div class="rounded bg-white/80 px-1.5 py-1 text-[10px] dark:bg-gray-900/60">
                                                <div class="flex items-baseline gap-1">
                                                    <span class="font-semibold text-gray-700 dark:text-gray-300" x-text="msg.sender_name"></span>
                                                    <span class="text-[9px] text-gray-400 dark:text-gray-500" x-text="'· ' + msg.created_at_human"></span>
                                                </div>
                                                <span class="text-gray-600 dark:text-gray-400" x-text="msg.body.length > 80 ? msg.body.slice(0, 80) + '…' : msg.body"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!loop.messages || !loop.messages.length">
                                    <p class="mt-1 text-[10px] text-gray-400 dark:text-gray-500 italic" x-text="i18n.noMessages"></p>
                                </template>
                                <a :href="loop.discussionUrl" target="_blank"
                                    class="mt-1 inline-block text-[10px] font-semibold text-indigo-600 dark:text-indigo-400 hover:underline"
                                    x-text="i18n.viewDiscussion"></a>

                                {{-- Message composer --}}
                                <template x-if="loop.is_member">
                                    <div class="mt-2 flex gap-1.5">
                                        <input type="text"
                                            x-model="messageDrafts[loop.id]"
                                            :placeholder="i18n.messagePlaceholder"
                                            @keydown.enter="sendMessage(loop.id)"
                                            class="flex-1 min-w-0 px-2 py-1 text-[10px] border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                                        >
                                        <button type="button"
                                            @click="sendMessage(loop.id)"
                                            :disabled="sendingMessage === loop.id || !(messageDrafts[loop.id] || '').trim()"
                                            class="shrink-0 px-2 py-1 text-[10px] font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded transition"
                                        >
                                            <span x-text="sendingMessage === loop.id ? i18n.messageSending : i18n.messageSend"></span>
                                        </button>
                                    </div>
                                </template>
                                <template x-if="!loop.is_member">
                                    <p class="mt-1.5 text-[10px] text-gray-400 dark:text-gray-500 italic" x-text="i18n.messageReadonly"></p>
                                </template>
                            </div>
                        </template>

                        {{-- Link form --}}
                        <template x-if="userLoops.length > 0">
                            <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                                <div class="flex gap-2">
                                    <select x-model="selectedLoopId"
                                        class="flex-1 px-2 py-1.5 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-1 focus:ring-indigo-500">
                                        <option value="" x-text="i18n.selectPlaceholder"></option>
                                        <template x-for="l in availableLoops" :key="l.id">
                                            <option :value="l.id" x-text="l.name"></option>
                                        </template>
                                    </select>
                                    <button type="button" @click="linkLoop()" :disabled="saving || !selectedLoopId"
                                        class="shrink-0 px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded transition">
                                        <span x-text="i18n.link"></span>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
                {{-- /Boucle card --}}

                {{-- Todo card --}}
                <div
                    x-data="blogTodoCard({
                        indexUrl: @js($_blogRoute('todos.index', ['post' => $post])),
                        storeUrl: @js($_blogRoute('todos.store', ['post' => $post])),
                        updateUrlBase: @js($_blogRoute('todos.update', ['post' => $post, 'todo' => '__TODO_ID__'])),
                        destroyUrlBase: @js($_blogRoute('todos.destroy', ['post' => $post, 'todo' => '__TODO_ID__'])),
                        threadStoreUrlBase: @js($_blogRoute('todos.threads.store', ['post' => $post, 'todo' => '__TODO_ID__'])),
                        threadDestroyUrlBase: @js($_blogRoute('todos.threads.destroy', ['post' => $post, 'todo' => '__TODO_ID__', 'thread' => '__THREAD_ID__'])),
                        assignableUsers: @js(
                            collect([['id' => $post->user_id, 'name' => $post->user->full_name]])
                                ->merge($post->coAuthors->map(fn($u) => ['id' => $u->id, 'name' => $u->full_name]))
                                ->unique('id')
                                ->values()
                                ->toArray()
                        ),
                        currentUserId: @js(auth()->id()),
                        i18n: {
                            title: @js(__('blog.todo_title')),
                            empty: @js(__('blog.todo_empty')),
                            create: @js(__('blog.todo_create')),
                            placeholder: @js(__('blog.todo_placeholder')),
                            statusTodo: @js(__('blog.todo_status_todo')),
                            statusInProgress: @js(__('blog.todo_status_in_progress')),
                            statusDone: @js(__('blog.todo_status_done')),
                            assign: @js(__('blog.todo_assign')),
                            unassigned: @js(__('blog.todo_unassigned')),
                            created: @js(__('blog.todo_created')),
                            updated: @js(__('blog.todo_updated')),
                            deleted: @js(__('blog.todo_deleted')),
                            notOwner: @js(__('blog.todo_not_owner')),
                            threadPlaceholder: @js(__('blog.todo_thread_placeholder')),
                            threadAdd: @js(__('blog.todo_thread_add')),
                            threadAdded: @js(__('blog.todo_thread_added')),
                            confirmDelete: @js(__('blog.todo_confirm_delete')),
                            loadError: @js(__('blog.todo_load_error')),
                            createError: @js(__('blog.todo_create_error')),
                            updateError: @js(__('blog.todo_update_error')),
                            deleteError: @js(__('blog.todo_delete_error')),
                            assignError: @js(__('blog.todo_assign_error')),
                            threadError: @js(__('blog.todo_thread_error')),
                            threadDeleteError: @js(__('blog.todo_thread_delete_error')),
                            hideComments: @js(__('blog.todo_hide_comments')),
                            showComments: @js(__('blog.todo_show_comments')),
                            addComment: @js(__('blog.todo_add_comment')),
                            csrfToken: @js(csrf_token()),
                        },
                    })"
                    class="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800"
                >
                    <button
                        @click="toggle()"
                        class="flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition"
                    >
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-orange-500 dark:text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                            <span>{{ __('blog.sidebar_todo') }}</span>
                        </span>
                        <svg
                            class="w-3 h-3 transition-transform"
                            :class="{ 'rotate-180': open }"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                        ><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    <div x-show="open" x-cloak class="px-3 pb-3 space-y-2 max-h-[min(26rem,calc(100vh-8rem))] overflow-y-auto">
                        <div x-show="success" x-cloak class="text-xs text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-2 py-1 rounded" x-text="success"></div>
                        <div x-show="error" x-cloak class="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded" x-text="error"></div>

                        {{-- Create form --}}
                        <div class="flex gap-1.5">
                            <input type="text"
                                x-model="newTitle"
                                :placeholder="i18n.placeholder"
                                @keydown.enter="createTodo()"
                                class="flex-1 min-w-0 px-2 py-1 text-[10px] border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                            >
                            <button type="button"
                                @click="createTodo()"
                                :disabled="creating || !newTitle.trim()"
                                class="shrink-0 px-2 py-1 text-[10px] font-semibold text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed rounded transition"
                                x-text="i18n.create"
                            ></button>
                        </div>
                        {{-- Assignee picker --}}
                        <div class="flex gap-1.5 items-center">
                            <span class="text-[9px] text-gray-400 shrink-0">{{ __('blog.todo_assign') }}</span>
                            <select x-model="newAssignee"
                                class="flex-1 text-[10px] border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 py-0.5 px-1"
                            >
                                <template x-for="u in assignableUsers" :key="u.id">
                                    <option :value="u.id" x-text="u.name"></option>
                                </template>
                            </select>
                        </div>

                        {{-- Tabs --}}
                        <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700 pb-1">
                            <template x-for="tab in ['todo','in_progress','done']" :key="tab">
                                <button type="button"
                                    @click="activeTab = tab"
                                    class="px-2 py-0.5 text-[10px] font-medium rounded transition"
                                    :class="activeTab === tab
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                                        : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'"
                                    x-text="tab === 'todo' ? i18n.statusTodo : (tab === 'in_progress' ? i18n.statusInProgress : i18n.statusDone)"
                                ></button>
                            </template>
                        </div>

                        {{-- Loading --}}
                        <div x-show="loading" class="text-xs text-gray-400 dark:text-gray-500 text-center py-4">
                            <span>Loading…</span>
                        </div>

                        {{-- Empty state --}}
                        <template x-if="!loading && filteredTodos.length === 0">
                            <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-4" x-text="i18n.empty"></p>
                        </template>

                        {{-- Todo list --}}
                        <template x-for="todo in filteredTodos" :key="todo.id">
                            <div class="rounded border border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-900/20 p-2 space-y-1" :class="{ 'opacity-60': todo.status === 'done' }">
                                <div class="flex items-start gap-1.5">
                                    {{-- Checkbox done/todo --}}
                                    <input type="checkbox"
                                        :checked="todo.status === 'done'"
                                        @change="toggleDone(todo)"
                                        class="mt-0.5 shrink-0 w-3 h-3 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                                    >
                                    <div class="flex-1 min-w-0">
                                        {{-- Title --}}
                                        <template x-if="editingTodo !== todo.id">
                                            <span class="block text-xs font-medium break-words cursor-pointer hover:text-indigo-600 dark:hover:text-indigo-400 transition"
                                                :class="todo.status === 'done' ? 'text-gray-400 dark:text-gray-500 line-through' : 'text-gray-800 dark:text-gray-100'"
                                                @click="startEdit(todo)"
                                                x-text="todo.title"
                                            ></span>
                                        </template>
                                        <template x-if="editingTodo === todo.id">
                                            <input type="text"
                                                x-model="editTitle"
                                                @keydown.enter="saveEdit(todo)"
                                                @keydown.escape="editingTodo = null"
                                                @click.away="saveEdit(todo)"
                                                class="w-full text-xs border border-gray-300 dark:border-gray-600 rounded px-1 py-0.5 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                                            >
                                        </template>
                                        {{-- Assigned to --}}
                                        <div class="text-[9px] text-gray-400 dark:text-gray-500 mt-0.5">
                                            <template x-if="editingAssignee !== todo.id">
                                                <button type="button" @click="startEditAssignee(todo)" class="hover:text-gray-600 dark:hover:text-gray-300 transition text-left">
                                                    <span x-text="todo.assigned_to_name ? (i18n.assign + ' ' + todo.assigned_to_name) : i18n.unassigned"></span>
                                                </button>
                                            </template>
                                            <template x-if="editingAssignee === todo.id">
                                                <select x-model="todo.assigned_to"
                                                    @change="saveEditAssignee(todo)"
                                                    @click.away="editingAssignee = null"
                                                    class="text-[9px] border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 py-0.5 px-1 max-w-full"
                                                >
                                                    <option value="" x-text="i18n.unassigned"></option>
                                                    <template x-for="u in assignableUsers" :key="u.id">
                                                        <option :value="u.id" x-text="u.name"></option>
                                                    </template>
                                                </select>
                                            </template>
                                        </div>
                                    </div>
                                    {{-- Actions --}}
                                    <div class="flex items-center gap-1 shrink-0">
                                        {{-- Status cycle --}}
                                        <select x-model="todo.status"
                                            @change="changeStatus(todo)"
                                            class="text-[9px] border border-gray-200 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 py-0.5 px-1"
                                        >
                                            <option value="todo" x-text="i18n.statusTodo"></option>
                                            <option value="in_progress" x-text="i18n.statusInProgress"></option>
                                            <option value="done" x-text="i18n.statusDone"></option>
                                        </select>
                                        {{-- Delete with inline confirmation --}}
                                        <template x-if="pendingDelete !== todo.id">
                                            <button type="button" @click="confirmDeleteTodo(todo)"
                                                class="text-[9px] text-red-400 hover:text-red-600 transition"
                                                title="Delete"
                                            >
                                                <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </template>
                                        <template x-if="pendingDelete === todo.id">
                                            <div class="flex items-center gap-1">
                                                <button type="button" @click="doDeleteTodo(todo)"
                                                    class="text-[9px] font-semibold text-white bg-red-500 hover:bg-red-600 px-1.5 py-0.5 rounded transition"
                                                    x-text="i18n.confirmDelete"
                                                ></button>
                                                <button type="button" @click="cancelDeleteTodo()"
                                                    class="text-[9px] text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition"
                                                >
                                                    <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                {{-- Thread section --}}
                                <div class="mt-1 pt-1 border-t border-gray-100 dark:border-gray-700">
                                    {{-- Thread toggle header --}}
                                    <button type="button" @click="toggleThreads(todo)"
                                        class="flex items-center gap-1 w-full text-left text-[9px] text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition py-0.5"
                                    >
                                        <svg class="w-2.5 h-2.5 transition-transform duration-150"
                                            :class="{ 'rotate-90': isThreadsOpen(todo) }"
                                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                        ><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        <span x-text="(todo.threads && todo.threads.length) ? (isThreadsOpen(todo) ? i18n.hideComments : i18n.showComments.replace(':count', todo.threads.length)) : i18n.addComment"></span>
                                    </button>
                                    {{-- Thread content (collapsed by default) --}}
                                    <div x-show="isThreadsOpen(todo)" x-cloak class="space-y-1 mt-1">
                                        <template x-if="todo.threads && todo.threads.length">
                                            <div class="space-y-1 mb-1.5">
                                                <template x-for="thr in todo.threads" :key="thr.id">
                                                    <div class="flex items-start justify-between gap-1 rounded bg-white/80 dark:bg-gray-900/60 px-1.5 py-1">
                                                        <div class="flex-1 min-w-0">
                                                            <span class="text-[9px] font-semibold text-gray-700 dark:text-gray-300" x-text="thr.sender_name"></span>
                                                            <span class="text-[9px] text-gray-400 dark:text-gray-500" x-text="'· ' + thr.created_at_human"></span>
                                                            <p class="text-[10px] text-gray-600 dark:text-gray-400 break-words" x-text="thr.body"></p>
                                                        </div>
                                                        <button type="button" @click="deleteThread(todo, thr)"
                                                            class="p-1 rounded text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 shrink-0 transition"
                                                            title="Delete comment"
                                                        >
                                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        </button>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <div class="flex gap-1">
                                            <input type="text"
                                                x-model="threadDrafts[todo.id]"
                                                :placeholder="i18n.threadPlaceholder"
                                                @keydown.enter="addThread(todo)"
                                                class="flex-1 min-w-0 px-1.5 py-0.5 text-[10px] border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                                            >
                                            <button type="button"
                                                @click="addThread(todo)"
                                                :disabled="sendingThread || !(threadDrafts[todo.id] || '').trim()"
                                                class="shrink-0 px-1.5 py-0.5 text-[10px] font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed rounded transition"
                                                x-text="i18n.threadAdd"
                                            ></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
                {{-- /Todo card --}}

                {{-- Plan card --}}
                <div
                    x-data="blogPlanCard({
                        csrfToken: @js(csrf_token()),
                        planUrl: @js($_blogRoute('plan.update', ['post' => $post])),
                        i18n: {
                            title: @js(__('blog.plan_title')),
                            empty: @js(__('blog.plan_empty')),
                            collapse: @js(__('blog.plan_collapse')),
                            expand: @js(__('blog.plan_expand')),
                            collapseAll: @js(__('blog.plan_collapse_all')),
                            expandAll: @js(__('blog.plan_expand_all')),
                            loading: @js(__('blog.plan_loading')),
                            updateError: @js(__('blog.plan_update_error')),
                        },
                    }                    )"
                    class="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800"
                >
                    <button type="button"
                        @click="toggle()"
                        class="flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition"
                    >
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-amber-500 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            <span>{{ __('blog.sidebar_plan') }}</span>
                        </span>
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

                        <template x-if="loading">
                            <p class="text-xs text-gray-400 text-center py-4" x-text="i18n.loading"></p>
                        </template>

                        <template x-if="!loading && headings.length === 0">
                            <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2" x-text="i18n.empty"></p>
                        </template>

                        <template x-if="!loading && headings.length > 0">
                            <div>
                                <div class="flex items-center gap-2 mb-2">
                                    <button type="button" @click="expandAll()" class="text-xs text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition" x-text="i18n.expandAll"></button>
                                    <span class="text-xs text-gray-300">|</span>
                                    <button type="button" @click="collapseAll()" class="text-xs text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition" x-text="i18n.collapseAll"></button>
                                </div>

                                <ul class="space-y-0.5">
                                    <template x-for="(h, i) in headings" :key="i">
                                        <li x-show="!h.parentCollapsed">
                                            <a :href="'#' + h.id"
                                               :style="{ paddingLeft: ((h.level - 1) * 12) + 'px' }"
                                               class="flex items-center gap-1 text-xs text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition py-0.5 truncate"
                                               @click.prevent="scrollToHeading(h.id)"
                                            >
                                                <template x-if="h.children && h.children.length > 0">
                                                    <span @click.prevent.stop="toggleCollapse(h)" class="shrink-0 w-3 h-3 flex items-center justify-center cursor-pointer hover:text-indigo-500">
                                                        <svg class="w-2.5 h-2.5 transition-transform" :class="{ 'rotate-90': !h.collapsed }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                                    </span>
                                                </template>
                                                <template x-if="!h.children || h.children.length === 0">
                                                    <span class="shrink-0 w-3 h-3"></span>
                                                </template>
                                                <span class="truncate" x-text="h.text"></span>
                                            </a>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                    </div>
                </div>
                {{-- /Plan card --}}

                {{-- Questionner selection card --}}
                <div
                    x-data="blogMethodSelectionCard({
                        selectionUrl: @js($_blogRoute('ai-method-selection')),
                        annotationStoreUrl: @js($_blogRoute('annotations.store', ['post' => $post])),
                        annotationContentSaveUrl: @js($_blogRoute('save-content', ['post' => $post])),
                        postId: @js($post->id),
                        csrfToken: @js(csrf_token()),
                        methods: [
                            { key: 'explorer', label: @js(__('blog.method_explorer')), description: @js(__('blog.method_explorer_desc')) },
                            { key: 'clarifier', label: @js(__('blog.method_clarifier')), description: @js(__('blog.method_clarifier_desc')) },
                            { key: 'slow_down', label: @js(__('blog.method_slow_down')), description: @js(__('blog.method_slow_down_desc')) },
                            { key: 'invent', label: @js(__('blog.method_invent')), description: @js(__('blog.method_invent_desc')) },
                        ],
                        i18n: {
                            title: @js(__('blog.sidebar_methode_ia')),
                            noSelection: @js(__('blog.method_selection_no_selection')),
                            selected: @js(__('blog.method_selection_selected')),
                            analyze: @js(__('blog.method_selection_analyze')),
                            analyzing: @js(__('blog.method_selection_analyzing')),
                            createAnnotation: @js(__('blog.method_selection_create_annotation')),
                            copy: @js(__('blog.method_selection_copy')),
                            copied: @js(__('blog.method_selection_copied')),
                            rerun: @js(__('blog.method_selection_rerun')),
                            deactivate: @js(__('blog.method_selection_deactivate')),
                            wholeArticle: @js(__('blog.method_selection_whole_article')),
                            wholeArticleHint: @js(__('blog.method_selection_whole_article_hint')),
                            resultLabel: @js(__('blog.method_selection_result_label')),
                            ready: @js(__('blog.method_selection_ready')),
                            error: @js(__('blog.method_selection_error')),
                        },
                    })"
                    class="border border-indigo-200 dark:border-indigo-800 rounded-lg bg-white dark:bg-gray-800"
                >
                    <button type="button"
                        @click="toggle()"
                        class="flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/20 rounded-lg transition"
                    >
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3 h-3 text-indigo-500 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18l-.813-2.096a4.5 4.5 0 00-2.591-2.591L3.5 12.5l2.096-.813a4.5 4.5 0 002.591-2.591L9 7l.813 2.096a4.5 4.5 0 002.591 2.591l2.096.813-2.096.813a4.5 4.5 0 00-2.591 2.591z"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 3l.56 1.44A3 3 0 0020 6l-1.44.56A3 3 0 0017 8l-.56-1.44A3 3 0 0015 5l1.44-.56A3 3 0 0018 3z"/></svg>
                            <span>{{ __('blog.sidebar_methode_ia') }}</span>
                        </span>
                        <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>

                    <div x-show="open" x-cloak class="space-y-3 px-3 pb-3">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('blog.method_selection_hint') }}</p>
                            <button type="button" x-show="active" x-cloak @click="deactivate()" class="shrink-0 rounded-full border border-violet-200 px-2.5 py-1 text-[11px] font-semibold text-violet-700 hover:bg-violet-50 dark:border-violet-800 dark:text-violet-200 dark:hover:bg-violet-950/30" x-text="i18n.deactivate"></button>
                        </div>

                        <button type="button" @click="openWholeArticleExplorer()" class="group flex w-full items-start gap-3 rounded-xl border border-purple-200 bg-gradient-to-br from-purple-50 to-white p-3 text-left transition hover:border-purple-300 hover:shadow-sm dark:border-purple-900 dark:from-purple-950/30 dark:to-gray-900">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-purple-600 text-white shadow-sm shadow-purple-900/20">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-purple-950 dark:text-purple-100" x-text="i18n.wholeArticle"></span>
                                <span class="mt-0.5 block text-xs leading-5 text-purple-700/80 dark:text-purple-200/80" x-text="i18n.wholeArticleHint"></span>
                            </span>
                        </button>

                        <div x-show="!active || !selectedText" x-cloak class="rounded-lg border border-dashed border-violet-200 bg-violet-50/50 p-3 text-xs leading-5 text-violet-800 dark:border-violet-900 dark:bg-violet-950/20 dark:text-violet-200">
                            {{ __('blog.method_selection_no_selection') }}
                        </div>

                        <div x-show="active && selectedText" x-cloak class="space-y-3">
                            <div class="rounded-lg bg-gray-50 p-3 text-xs leading-5 text-gray-600 dark:bg-gray-900/60 dark:text-gray-300">
                                <p class="mb-1 font-semibold text-gray-500 dark:text-gray-400" x-text="i18n.selected"></p>
                                <p class="line-clamp-3 italic" x-text="selectedText"></p>
                            </div>

                            <div class="grid grid-cols-1 gap-2">
                                <template x-for="m in methods" :key="m.key">
                                    <button type="button" @click="selectMethod(m.key)"
                                        class="rounded-xl border px-3 py-2 text-left transition"
                                        :class="method === m.key ? 'border-violet-300 bg-violet-50 text-violet-950 dark:border-violet-700 dark:bg-violet-950/30 dark:text-violet-100' : 'border-gray-200 bg-white text-gray-700 hover:border-violet-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-violet-800'">
                                        <span class="block text-sm font-semibold" x-text="m.label"></span>
                                        <span class="block text-xs leading-5 text-gray-500 dark:text-gray-400" x-text="m.description"></span>
                                    </button>
                                </template>
                            </div>

                            <div x-show="error" x-cloak class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-900/20 dark:text-red-300" x-text="error"></div>
                            <div x-show="success" x-cloak class="rounded-lg bg-green-50 px-3 py-2 text-xs text-green-700 dark:bg-green-900/20 dark:text-green-300" x-text="success"></div>

                            <div x-show="suggestion" x-cloak class="space-y-2">
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400" x-text="i18n.resultLabel"></label>
                                <textarea x-model="suggestion" rows="7" class="w-full rounded-xl border border-violet-200 bg-white p-3 text-sm leading-6 text-gray-900 focus:border-violet-500 focus:ring-1 focus:ring-violet-500 dark:border-violet-900 dark:bg-gray-900 dark:text-gray-100"></textarea>
                            </div>

                            <div class="flex flex-wrap justify-end gap-2">
                                <button type="button" @click="analyze()" :disabled="loading" class="rounded-xl bg-violet-600 px-4 py-2 text-xs font-semibold text-white hover:bg-violet-700 disabled:opacity-50">
                                    <span x-show="!loading && !suggestion" x-text="i18n.analyze"></span>
                                    <span x-show="!loading && suggestion" x-text="i18n.rerun"></span>
                                    <span x-show="loading" x-text="i18n.analyzing"></span>
                                </button>
                                <button type="button" @click="copySuggestion()" :disabled="!suggestion" class="rounded-xl border border-gray-200 px-4 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" x-text="copied ? i18n.copied : i18n.copy"></button>
                                <button type="button" @click="createAnnotation()" :disabled="!suggestion" class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" x-text="i18n.createAnnotation"></button>
                            </div>
                        </div>
                    </div>
                </div>
                {{-- /Questionner selection card --}}

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
                            sourceAll: @js(__('blog.annotation_source_all')),
                            sourceHuman: @js(__('blog.annotation_source_human')),
                            sourceAiMethod: @js(__('blog.annotation_source_ai_method')),
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
                            <svg class="w-3 h-3 text-blue-500 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
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

                        {{-- Source filters --}}
                        <div class="flex flex-wrap gap-1">
                            <button type="button" @click="sourceFilter = 'all'"
                                :class="sourceFilter === 'all' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'bg-gray-50 text-gray-500 hover:text-gray-700 dark:bg-gray-900 dark:text-gray-400 dark:hover:text-gray-200'"
                                class="px-2 py-1 text-[10px] font-semibold rounded transition"
                                x-text="i18n.sourceAll"></button>
                            <button type="button" @click="sourceFilter = 'human'"
                                :class="sourceFilter === 'human' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'bg-gray-50 text-gray-500 hover:text-gray-700 dark:bg-gray-900 dark:text-gray-400 dark:hover:text-gray-200'"
                                class="px-2 py-1 text-[10px] font-semibold rounded transition"
                                x-text="i18n.sourceHuman"></button>
                            <button type="button" @click="sourceFilter = 'ai_method'"
                                :class="sourceFilter === 'ai_method' ? 'bg-indigo-600 text-white dark:bg-indigo-500' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/30 dark:text-indigo-300 dark:hover:bg-indigo-950/50'"
                                class="px-2 py-1 text-[10px] font-semibold rounded transition"
                                x-text="i18n.sourceAiMethod"></button>
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
                                        <template x-if="a.origin === 'ai_method'">
                                            <div class="flex flex-wrap items-center gap-1">
                                                <span class="inline-flex items-center rounded-full bg-indigo-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300" x-text="a.source_label"></span>
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-1.5 py-0.5 text-[9px] font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300" x-text="a.method_label"></span>
                                                <span class="text-[9px] text-gray-400 dark:text-gray-500" x-text="a.requested_by_label"></span>
                                            </div>
                                        </template>
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

    {{-- Explorer modal --}}
    <div
        x-data="blogExplorerModal({
            chatUrl: @js($_blogRoute('explorer.chat', ['post' => $post])),
            noteGenerateUrl: @js($_blogRoute('explorer.note.generate', ['post' => $post])),
            notesStoreUrl: @js($_blogRoute('explorer.notes.store', ['post' => $post])),
            csrfToken: @js(csrf_token()),
            noteMaxChars: 3000,
            i18n: {
                title: @js(__('blog.explorer_title')),
                chatPlaceholder: @js(__('blog.explorer_chat_placeholder')),
                generateNote: @js(__('blog.explorer_generate_note')),
                generatingNote: @js(__('blog.explorer_generating_note')),
                noteTitle: @js(__('blog.explorer_note_title')),
                noteSave: @js(__('blog.explorer_note_save')),
                noteSaved: @js(__('blog.explorer_note_saved')),
                noteSaveError: @js(__('blog.explorer_note_save_error')),
                noteMinMax: @js(__('blog.explorer_note_min_chars')),
                noteMax: @js(__('blog.explorer_note_max_chars')),
                notePlaceholder: @js(__('blog.explorer_note_placeholder')),
                backToChat: @js(__('blog.explorer_back_to_chat')),
                close: @js(__('blog.explorer_close')),
                dialogueCount: @js(__('blog.explorer_dialogue_count')),
                deepChatError: @js(__('blog.explorer_deep_chat_error')),
                introMessage: @js(__('blog.explorer_intro_message')),
                articleNotSaved: @js(__('blog.explorer_article_not_saved')),
            },
        })"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40"
        @keydown.escape.window="close()"
    >
        <div class="bg-white dark:bg-gray-800 rounded-t-2xl md:rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 w-full md:max-w-[920px] md:mx-4 h-[88dvh] max-h-[88dvh] flex flex-col" @click.stop>
            {{-- Header --}}
            <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 dark:border-gray-700">
                <div>
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <svg class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <span x-text="i18n.title || 'Explorer'"></span>
                    </h3>
                </div>
                <div class="flex items-center gap-3">
                    <span x-show="phase === 'dialogue' && dialogueCount > 0" x-cloak class="text-[11px] text-gray-400 dark:text-gray-500" x-text="dialogueLabel"></span>
                    <button type="button" @click="close()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            {{-- Dialogue phase --}}
            <div x-show="phase === 'dialogue'" class="flex-1 min-h-0 flex flex-col">
                <div class="flex-1 min-h-0 overflow-hidden p-4 flex">
                    <template x-if="open">
                        <deep-chat
                            x-ref="deepChat"
                            class="block w-full h-full flex-1 rounded-lg border border-gray-200 dark:border-gray-600 overflow-hidden"
                            style="display: block; width: 100%; height: 100%;"
                        ></deep-chat>
                    </template>
                </div>
                <div class="flex items-center justify-end px-5 py-3 border-t border-gray-100 dark:border-gray-700">
                    <div class="flex items-center gap-2">
                        <button type="button" @click="close()" class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">
                            {{ __('blog.explorer_close') }}
                        </button>
                        <button type="button" @click="generateNote()" :disabled="!canGenerateNote || generatingNote"
                            class="px-3 py-1.5 text-xs font-semibold rounded-lg transition inline-flex items-center gap-1.5"
                            :class="canGenerateNote ? 'bg-purple-600 text-white hover:bg-purple-700 disabled:opacity-50' : 'bg-gray-100 text-gray-400 cursor-not-allowed dark:bg-gray-700 dark:text-gray-500'">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <span x-text="i18n.generateNote || 'Générer la note'"></span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Article unavailable phase --}}
            <div x-show="phase === 'unavailable'" x-cloak class="flex-1 flex items-center justify-center p-8">
                <div class="max-w-md text-center">
                    <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.007v.008H12v-.008zM10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    </div>
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.title || 'Questionner le texte'"></h4>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300" x-text="i18n.articleNotSaved || ''"></p>
                    <button type="button" @click="close()" class="mt-5 px-4 py-2 text-xs font-semibold rounded-lg bg-purple-600 text-white hover:bg-purple-700 transition">
                        {{ __('blog.explorer_close') }}
                    </button>
                </div>
            </div>

            {{-- Generating phase --}}
            <div x-show="phase === 'generating'" x-cloak class="flex-1 flex items-center justify-center p-8">
                <div class="text-center">
                    <svg class="animate-spin h-8 w-8 text-purple-600 dark:text-purple-400 mx-auto mb-3" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <p class="text-sm text-gray-600 dark:text-gray-300 font-medium" x-text="i18n.generatingNote || 'Génération en cours...'"></p>
                </div>
            </div>

            {{-- Note phase --}}
            <div x-show="phase === 'note'" x-cloak class="flex-1 flex flex-col min-h-0">
                <div class="flex-1 overflow-y-auto p-5 space-y-4">
                    <div x-show="noteTextLength > maxNoteChars" x-cloak class="text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 px-3 py-2 rounded-lg">
                        <span x-text="(i18n.noteMax || '').replace(':max', maxNoteChars)"></span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400" x-show="noteTextLength > 0">
                        <span x-text="noteTextLength"></span> / <span x-text="maxNoteChars"></span>
                    </div>
                    <div class="rounded-lg border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800 overflow-hidden">
                        <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-900/60">
                            <button type="button" @click="noteCommand('bold')" :class="isNoteActive('bold') ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'text-gray-600 dark:text-gray-300'" class="rounded px-2 py-1 text-xs font-semibold hover:bg-gray-100 dark:hover:bg-gray-700">B</button>
                            <button type="button" @click="noteCommand('italic')" :class="isNoteActive('italic') ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'text-gray-600 dark:text-gray-300'" class="rounded px-2 py-1 text-xs italic hover:bg-gray-100 dark:hover:bg-gray-700">I</button>
                            <span class="mx-1 h-4 w-px bg-gray-200 dark:bg-gray-700"></span>
                            <button type="button" @click="noteCommand('heading3')" :class="isNoteActive('heading', { level: 3 }) ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'text-gray-600 dark:text-gray-300'" class="rounded px-2 py-1 text-xs font-semibold hover:bg-gray-100 dark:hover:bg-gray-700">Titre</button>
                            <button type="button" @click="noteCommand('bulletList')" :class="isNoteActive('bulletList') ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'text-gray-600 dark:text-gray-300'" class="rounded px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-gray-700">Liste</button>
                        </div>
                        <div x-ref="noteEditor" class="bp-note-editor min-h-[360px] px-4 py-3 text-sm leading-relaxed text-gray-900 dark:text-gray-100"></div>
                    </div>
                    <div x-show="noteTextLength > 0 && noteTextLength < 150" x-cloak class="text-xs text-amber-600 dark:text-amber-400">
                        <span x-text="(i18n.noteMinMax || 'Min : :min caractères').replace(':min', '150').replace(':max', maxNoteChars)"></span>
                    </div>
                </div>

                <div x-show="error" x-cloak class="mx-5 mb-3 text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 px-3 py-2 rounded-lg" x-text="error"></div>
                <div x-show="success" x-cloak class="mx-5 mb-3 text-sm text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-3 py-2 rounded-lg" x-text="success"></div>

                <div class="flex items-center justify-between px-5 py-3 border-t border-gray-100 dark:border-gray-700">
                    <button type="button" @click="backToDialogue()" :disabled="saving"
                        class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition disabled:opacity-50">
                        <span x-text="i18n.backToChat || '← Revenir au chat'"></span>
                    </button>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="close()" :disabled="saving"
                            class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition disabled:opacity-50">
                            {{ __('blog.btn_cancel') }}
                        </button>
                        <button type="button" @click="saveNote()" :disabled="saving || noteTextLength < 150 || noteTextLength > maxNoteChars"
                            class="px-4 py-1.5 text-xs font-semibold text-white bg-purple-600 hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition inline-flex items-center gap-1.5">
                            <svg x-show="saving" class="animate-spin h-3.5 w-3.5 text-white" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            <span x-text="saving ? '...' : (i18n.noteSave || 'Sauvegarder')"></span>
                        </button>
                    </div>
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
                <span class="flex items-center gap-1.5">
                    <svg class="w-3 h-3 text-emerald-500 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>{{ __('blog.sidebar_co_ecriture') }}</span>
                </span>
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

                {{-- Invitation by email --}}
                <div x-data="blogInviteByEmail({
                    inviteStoreUrl: @js($_blogRoute('invite.store', ['post' => $post])),
                    inviteIndexUrl: @js($_blogRoute('invite.index', ['post' => $post])),
                    isOwner: {{ Auth::id() === $post->user_id ? 'true' : 'false' }},
                    isAdmin: {{ Auth::user()->is_admin ? 'true' : 'false' }},
                    i18n: {
                        btnInvite: @js(__('blog-invitation.btn_invite_email')),
                        modalTitle: @js(__('blog-invitation.modal_title')),
                        modalEmail: @js(__('blog-invitation.modal_recipient_email')),
                        modalName: @js(__('blog-invitation.modal_recipient_name')),
                        modalMessage: @js(__('blog-invitation.modal_message')),
                        modalPlaceholderEmail: @js(__('blog-invitation.modal_placeholder_email')),
                        modalPlaceholderName: @js(__('blog-invitation.modal_placeholder_name')),
                        modalPlaceholderMessage: @js(__('blog-invitation.modal_placeholder_message')),
                        modalBtnSend: @js(__('blog-invitation.modal_btn_send')),
                        modalBtnCancel: @js(__('blog-invitation.modal_btn_cancel')),
                        modalSending: @js(__('blog-invitation.modal_sending')),
                        modalNotice: @js(__('blog-invitation.modal_notice')),
                        cardTitle: @js(__('blog-invitation.card_title')),
                        cardEmpty: @js(__('blog-invitation.card_empty')),
                        cardTypeExisting: @js(__('blog-invitation.card_type_existing')),
                        cardTypeExternal: @js(__('blog-invitation.card_type_external')),
                        cardStatusSent: @js(__('blog-invitation.card_status_sent')),
                        cardStatusFailed: @js(__('blog-invitation.card_status_failed')),
                        cardNoName: @js(__('blog-invitation.card_no_name')),
                        cardViewAll: @js(__('blog-invitation.card_view_all')),
                        sent: @js(__('blog-invitation.sent_to_external')),
                        errorInvalidEmail: @js('Veuillez saisir une adresse email valide.'),
                        errorSendFailed: @js(__('blog-invitation.email_error')),
                        csrfToken: @js(csrf_token()),
                    },
                })" x-cloak>
                    <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                        <button
                            x-show="canInvite()"
                            type="button"
                            @click="openModal()"
                            class="w-full flex items-center justify-center gap-2 px-3 py-2 text-xs font-semibold text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-lg transition"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <span x-text="i18n.btnInvite"></span>
                        </button>
                    </div>

                    {{-- Invitation history --}}
                    <div class="border-t border-gray-100 dark:border-gray-700 pt-3 mt-3">
                        <button type="button" @click="toggleHistory()" class="flex items-center justify-between w-full text-[11px] font-semibold text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition">
                            <span x-text="i18n.cardTitle"></span>
                            <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': showHistory }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="showHistory" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1" class="mt-2 space-y-1.5">
                            <template x-if="loadingHistory">
                                <div class="flex items-center justify-center py-3">
                                    <svg class="animate-spin h-3.5 w-3.5 text-gray-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                </div>
                            </template>
                            <template x-if="!loadingHistory && invitations.length === 0">
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 text-center py-2" x-text="i18n.cardEmpty"></p>
                            </template>
                            <template x-for="inv in invitations" :key="inv.id">
                                <div class="flex items-center justify-between gap-2 rounded-lg border border-gray-100 bg-gray-50/70 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-900/50">
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-medium text-gray-800 dark:text-gray-100" x-text="inv.to_email"></p>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <span class="inline-block px-1.5 py-0.5 text-[9px] font-semibold rounded"
                                                :class="inv.invitation_type === 'existing_member' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'"
                                                x-text="inv.invitation_type === 'existing_member' ? i18n.cardTypeExisting : i18n.cardTypeExternal"></span>
                                            <span class="text-[9px] text-gray-400 dark:text-gray-500" x-text="formatDate(inv.sent_at)"></span>
                                        </div>
                                    </div>
                                    <span class="shrink-0 text-[9px] font-medium"
                                        :class="inv.status === 'sent' ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400'"
                                        x-text="inv.status === 'sent' ? i18n.cardStatusSent : i18n.cardStatusFailed"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Invitation modal --}}
                    <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @keydown.escape.window="closeModal()">
                        <div @click.away="closeModal()" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl max-w-md w-full mx-4 p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4" x-text="i18n.modalTitle"></h3>

                            <div x-show="success" x-cloak class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 text-sm text-green-700 dark:text-green-300" x-text="success"></div>
                            <div x-show="error" x-cloak class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-sm text-red-700 dark:text-red-300" x-text="error"></div>

                            <div class="space-y-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" x-text="i18n.modalEmail + ' *'"></label>
                                    <input type="email" x-model="recipientEmail" :placeholder="i18n.modalPlaceholderEmail" required
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" x-text="i18n.modalName"></label>
                                    <input type="text" x-model="recipientName" :placeholder="i18n.modalPlaceholderName"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" x-text="i18n.modalMessage"></label>
                                    <textarea x-model="message" rows="3" :placeholder="i18n.modalPlaceholderMessage"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm"></textarea>
                                </div>
                                <p class="text-[10px] leading-4 text-gray-400 dark:text-gray-500" x-text="i18n.modalNotice"></p>
                            </div>
                            <div class="flex justify-end gap-3 mt-5">
                                <button type="button" @click="closeModal()" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition" x-text="i18n.modalBtnCancel"></button>
                                <button type="button" @click="sendInvite()" :disabled="sending || !recipientEmail" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition disabled:opacity-50">
                                    <span x-show="!sending" x-text="i18n.modalBtnSend"></span>
                                    <span x-show="sending" x-cloak x-text="i18n.modalSending"></span>
                                </button>
                            </div>
                        </div>
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
                <span class="flex items-center gap-1.5">
                    <svg class="w-3 h-3 text-violet-500 dark:text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-1.414-1.414a2 2 0 00-1.414-.586H9.656a2 2 0 00-1.414.586L6.828 7H4a2 2 0 00-2 2v7a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2h-2.828zM12 10a3 3 0 100 6 3 3 0 000-6z"/>
                    </svg>
                    <span>{{ __('blog.sidebar_snapshot') }}</span>
                </span>
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

        {{-- Explorer notes card --}}
        <div
            x-data="blogExplorerCard({
                indexUrl: @js($_blogRoute('explorer.notes.index', ['post' => $post])),
                updateUrlBase: @js($_blogRoute('explorer.notes.update', ['post' => $post, 'note' => '__NOTE_ID__'])),
                destroyUrlBase: @js($_blogRoute('explorer.notes.destroy', ['post' => $post, 'note' => '__NOTE_ID__'])),
                csrfToken: @js(csrf_token()),
                i18n: {
                    loadError: @js(__('blog.explorer_note_save_error')),
                    noteSaved: @js(__('blog.explorer_note_saved')),
                    noteSaveError: @js(__('blog.explorer_note_save_error')),
                    noteDeleted: @js(__('blog.explorer_note_deleted')),
                    deleteError: @js(__('blog.explorer_note_delete_error')),
                    noNotes: @js(__('blog.explorer_no_notes')),
                    sidebarTitle: @js(__('blog.sidebar_notes')),
                    noteFrom: @js(__('blog.explorer_note_from')),
                    notePlaceholder: @js(__('blog.explorer_note_placeholder')),
                    btnEdit: @js(__('blog.explorer_note_edit')),
                    btnSave: @js(__('blog.explorer_note_save')),
                    btnCancel: @js(__('blog.explorer_note_cancel')),
                    btnDelete: @js(__('blog.explorer_note_delete')),
                    deleteConfirm: @js(__('blog.explorer_note_delete_confirm')),
                },
            })"
            class="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800"
        >
            <button
                @click="toggle()"
                class="flex items-center justify-between w-full px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg transition"
            >
                <span class="flex items-center gap-1.5">
                    <svg class="w-3 h-3 text-purple-500 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.8 9.8 0 01-3.55-.644L3 21l1.395-3.72A7.95 7.95 0 013 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/>
                    </svg>
                    <span x-text="i18n.sidebarTitle || 'Notes'"></span>
                </span>
                <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div x-show="open" x-cloak class="px-3 pb-3 space-y-3 max-h-[min(24rem,calc(100vh-8rem))] overflow-y-auto">
                <div x-show="success" x-cloak class="text-xs text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-2 py-1 rounded" x-text="success"></div>
                <div x-show="error" x-cloak class="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 px-2 py-1 rounded" x-text="error"></div>

                <template x-if="loading && notes.length === 0">
                    <div class="flex items-center justify-center py-4">
                        <svg class="animate-spin h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    </div>
                </template>

                <template x-if="!loading && notes.length === 0">
                    <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-2" x-text="i18n.noNotes || 'Aucune note.'"></p>
                </template>

                <template x-for="note in notes" :key="note.id">
                    <div class="rounded-lg border border-gray-100 bg-gray-50/70 p-2 dark:border-gray-700 dark:bg-gray-900/50 group transition" :class="highlightedId === note.id ? 'ring-2 ring-purple-400 bg-purple-50/70 dark:bg-purple-900/20' : ''">
                        <div class="flex items-start justify-between gap-2">
                            <button type="button" @click="openNote(note)" class="min-w-0 flex-1 text-left">
                                <p class="text-[10px] text-gray-500 dark:text-gray-400 mb-1" x-text="(i18n.noteFrom || 'Note du :date').replace(':date', note.created_at || '') + (note.user_name ? ' · ' + note.user_name : '')"></p>
                                <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed line-clamp-3" x-text="truncate(note.note_content, 150)"></p>
                            </button>
                            <button type="button" @click="if(confirm(i18n.deleteConfirm || 'Supprimer cette note ?')) deleteNote(note.id)" :disabled="deletingId === note.id"
                                class="shrink-0 text-gray-300 hover:text-red-500 dark:text-gray-600 dark:hover:text-red-400 transition opacity-0 group-hover:opacity-100 disabled:opacity-50"
                                :title="i18n.btnDelete || 'Supprimer'">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="selectedNote" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center bg-black/40 md:items-center" @keydown.escape.window="closeNoteModal()">
                <div class="flex h-[82dvh] max-h-[82dvh] w-full flex-col rounded-t-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800 md:mx-4 md:max-w-[860px] md:rounded-xl" @click.stop>
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                        <div>
                            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100" x-text="i18n.sidebarTitle || 'Questionnements'"></h3>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400" x-text="selectedNote ? (i18n.noteFrom || 'Note du :date').replace(':date', selectedNote.created_at || '') : ''"></p>
                        </div>
                        <button type="button" @click="closeNoteModal()" class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto bg-slate-50/80 p-5 dark:bg-gray-900/40">
                        <template x-if="selectedNote && !editingNote">
                            <article class="bp-questioning-reader mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800/95" x-html="renderQuestioning(selectedNote.note_content)"></article>
                        </template>
                        <template x-if="selectedNote && editingNote">
                            <div class="rounded-lg border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                                <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50 px-2 py-1.5 dark:border-gray-700 dark:bg-gray-900/60">
                                    <button type="button" @click="noteCommand('bold')" :class="isNoteActive('bold') ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'text-gray-600 dark:text-gray-300'" class="rounded px-2 py-1 text-xs font-semibold hover:bg-gray-100 dark:hover:bg-gray-700">B</button>
                                    <button type="button" @click="noteCommand('italic')" :class="isNoteActive('italic') ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'text-gray-600 dark:text-gray-300'" class="rounded px-2 py-1 text-xs italic hover:bg-gray-100 dark:hover:bg-gray-700">I</button>
                                    <span class="mx-1 h-4 w-px bg-gray-200 dark:bg-gray-700"></span>
                                    <button type="button" @click="noteCommand('heading3')" :class="isNoteActive('heading', { level: 3 }) ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'text-gray-600 dark:text-gray-300'" class="rounded px-2 py-1 text-xs font-semibold hover:bg-gray-100 dark:hover:bg-gray-700">Titre</button>
                                    <button type="button" @click="noteCommand('bulletList')" :class="isNoteActive('bulletList') ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200' : 'text-gray-600 dark:text-gray-300'" class="rounded px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-gray-700">Liste</button>
                                </div>
                                <div x-ref="questionEditor" class="bp-note-editor min-h-[420px] px-4 py-3 text-sm leading-relaxed text-gray-900 dark:text-gray-100"></div>
                            </div>
                        </template>
                    </div>

                    <div class="flex items-center justify-between border-t border-gray-100 px-5 py-3 dark:border-gray-700">
                        <button type="button" @click="if(confirm(i18n.deleteConfirm || 'Supprimer cette note ?')) deleteNote(selectedNote.id)" :disabled="!selectedNote || deletingId === selectedNote?.id || savingNote" class="px-3 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-50 dark:text-red-400 dark:hover:bg-red-900/20" x-text="i18n.btnDelete || 'Supprimer'"></button>
                        <div class="flex items-center gap-2">
                            <button type="button" x-show="!editingNote" @click="startEditNote()" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" x-text="i18n.btnEdit || 'Modifier'"></button>
                            <button type="button" x-show="editingNote" @click="cancelEditNote()" :disabled="savingNote" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-gray-700" x-text="i18n.btnCancel || 'Annuler'"></button>
                            <button type="button" x-show="editingNote" @click="saveSelectedNote()" :disabled="savingNote" class="rounded-lg bg-purple-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-purple-700 disabled:opacity-50" x-text="savingNote ? '...' : (i18n.btnSave || 'Enregistrer')"></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        {{-- /Explorer notes card --}}

    </aside>

    </div>
</div>
</x-app-layout>
