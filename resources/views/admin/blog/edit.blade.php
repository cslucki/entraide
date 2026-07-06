<x-admin-layout title="{{ __('blog.title_edit', ['title' => $post->title]) }}">
    <div class="mb-6">
        <a href="{{ route('admin.blog') }}" class="text-sm text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400">
            {{ __('blog.back_to_list') }}
        </a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">{{ __('blog.heading_edit') }}</h1>

        <form action="{{ route('admin.blog.update', $post) }}" method="POST" enctype="multipart/form-data" class="space-y-6"
              x-data="{
                  selectedOrgId: '{{ old('organization_id', $post->organization_id) }}',
                  categoriesByOrg: {{ Js::from($categories->groupBy('organization_id')->map->values()->toArray()) }},
                  get filteredCategories() {
                      return (this.categoriesByOrg[this.selectedOrgId] || []);
                  }
              }">
            @csrf @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Organisation --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_organization') }}</label>
                    <select name="organization_id" required x-model="selectedOrgId"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                        @foreach($organizations as $org)
                        <option value="{{ $org->id }}" {{ old('organization_id', $post->organization_id) === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
                        @endforeach
                    </select>
                    @error('organization_id')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Auteur --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_author') }}</label>
                    <select name="user_id" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                        @foreach($usersByOrg as $orgName => $orgUsers)
                        <optgroup label="{{ $orgName }}">
                            @foreach($orgUsers as $user)
                            <option value="{{ $user->id }}" {{ old('user_id', $post->user_id) === $user->id ? 'selected' : '' }}>
                                {{ $user->fullName }} ({{ $user->email }})
                            </option>
                            @endforeach
                        </optgroup>
                        @endforeach
                    </select>
                    @error('user_id')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            {{-- Titre --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_title') }}</label>
                <input type="text" name="title" value="{{ old('title', $post->title) }}" required
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                @error('title')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Slug --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_slug') }}</label>
                <input type="text" name="slug" value="{{ old('slug', $post->slug) }}"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm font-mono">
                <p class="text-xs text-gray-400 mt-1">{{ __('blog.slug_help') }}</p>
                @error('slug')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Résumé --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_summary') }}</label>
                <textarea name="summary" rows="2" maxlength="500"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">{{ old('summary', $post->summary) }}</textarea>
                @error('summary')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Contenu + Markdown Preview --}}
            <div x-data="{ tab: 'editor' }">
                <div class="flex items-center gap-1 mb-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('blog.label_content') }}</label>
                    <span class="ml-auto flex gap-1">
                        <button type="button" @click="tab = 'editor'"
                            :class="tab === 'editor' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                            class="px-3 py-1 text-xs font-medium rounded-lg transition">✏️ {{ __('blog.editor_editor_tab') }}</button>
                        <button type="button" @click="tab = 'preview'; $nextTick(() => renderPreview())"
                            :class="tab === 'preview' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                            class="px-3 py-1 text-xs font-medium rounded-lg transition">👁️ {{ __('blog.editor_preview_tab') }}</button>
                    </span>
                </div>

                <div x-show="tab === 'editor'">
                    <textarea name="content" id="md-content" rows="16" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm font-mono">{{ old('content', $post->content) }}</textarea>
                    @error('content')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div x-show="tab === 'preview'" x-cloak>
                    <div id="markdown-preview"
                         class="w-full min-h-[24rem] px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 prose prose-sm dark:prose-invert max-w-none overflow-auto">
                        <p class="text-gray-400 italic">{{ __('blog.loading') }}</p>
                    </div>
                </div>
            </div>

            {{-- Image de couverture --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_cover_image') }}</label>
                @if($post->image)
                <div class="mb-2">
                    <img src="{{ $post->image_url }}" alt="" class="h-24 rounded-lg object-cover">
                </div>
                @endif
                <input type="file" name="image" accept="image/*"
                    class="w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                @error('image')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Catégorie --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('blog.label_category') }}</label>
                    <select name="category_id"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                        <option value="">{{ __('blog.option_none') }}</option>
                        <template x-for="cat in filteredCategories" :key="cat.id">
                            <option :value="cat.id" x-text="cat.name_b2c"
                                :selected="cat.id === '{{ old('category_id', $post->category_id) }}'"></option>
                        </template>
                    </select>
                    @error('category_id')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Tags --}}
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
                    @error('tags')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            {{-- Statut --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('blog.label_status') }}</label>
                <div class="flex gap-4 flex-wrap">
                    @foreach(['draft' => __('blog.status_draft'), 'pending' => __('blog.status_pending'), 'published' => __('blog.status_published'), 'archived' => __('blog.status_archived')] as $val => $label)
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="radio" name="status" value="{{ $val }}" {{ old('status', $post->status) === $val ? 'checked' : '' }} class="text-indigo-600">
                        {{ $label }}
                    </label>
                    @endforeach
                </div>
                @error('status')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- SEO --}}
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

            {{-- Actions --}}
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition">
                    {{ __('blog.btn_save') }}
                </button>
                <a href="{{ route('admin.blog') }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    {{ __('blog.btn_cancel') }}
                </a>
                <a href="{{ route('blog.show', $post) }}" target="_blank"
                   class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition text-sm">
                    {{ __('blog.btn_view_article') }}
                </a>
            </div>
        </form>

        {{-- Suppression --}}
        <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700 flex justify-end">
            <form action="{{ route('admin.blog.destroy', $post) }}" method="POST"
                  onsubmit="return confirm('{{ __('blog.confirm_delete_post_admin_permanent', ['title' => addslashes($post->title)]) }}')">
                @csrf @method('DELETE')
                <button type="submit" class="px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                    {{ __('blog.btn_delete_post') }}
                </button>
            </form>
        </div>
    </div>
</x-admin-layout>

<script>
function renderPreview() {
    const content = document.getElementById('md-content').value;
    const preview = document.getElementById('markdown-preview');
    preview.innerHTML = '<p class="text-gray-400 italic">' + '{{ __('blog.loading') }}' + '</p>';

    fetch('{{ route('admin.blog.preview-markdown') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ content })
    })
    .then(r => r.json())
    .then(data => {
        preview.innerHTML = data.html;
    })
    .catch(() => {
        preview.innerHTML = '<p class="text-red-500">' + '{{ __('blog.editor_render_error') }}' + '</p>';
    });
}
</script>
