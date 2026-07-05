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
                    { key: 'snapshot', label: @js(__('blog.sidebar_snapshot')), open: false, placeholder: @js(__('blog.sidebar_snapshot_placeholder')) },
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
        class="shrink-0 hidden md:flex flex-col space-y-2 overflow-y-auto max-h-[80vh]"
    >
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
                <div x-show="card.open" x-cloak class="px-3 pb-3 text-xs text-gray-500 dark:text-gray-400">
                    <span x-text="card.placeholder"></span>
                </div>
            </div>
        </template>
    </aside>

    </div>
</div>
</x-app-layout>
