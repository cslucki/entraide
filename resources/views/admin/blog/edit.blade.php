<x-admin-layout title="Modifier — {{ $post->title }}">
    <div class="mb-6">
        <a href="{{ route('admin.blog') }}" class="text-sm text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400">
            ← Retour à la liste des articles
        </a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">Modifier l'article</h1>

        <form action="{{ route('admin.blog.update', $post) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
            @csrf @method('PUT')

            {{-- Auteur --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Auteur *</label>
                <select name="user_id" required
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                    @foreach($users as $user)
                    <option value="{{ $user->id }}" {{ old('user_id', $post->user_id) === $user->id ? 'selected' : '' }}>
                        {{ $user->name }} ({{ $user->email }})
                    </option>
                    @endforeach
                </select>
                @error('user_id')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Titre --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre *</label>
                <input type="text" name="title" value="{{ old('title', $post->title) }}" required
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                @error('title')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Slug --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Slug</label>
                <input type="text" name="slug" value="{{ old('slug', $post->slug) }}"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm font-mono">
                <p class="text-xs text-gray-400 mt-1">Laissez vide pour génération automatique.</p>
                @error('slug')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Résumé --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Résumé</label>
                <textarea name="summary" rows="2" maxlength="500"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">{{ old('summary', $post->summary) }}</textarea>
                @error('summary')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Contenu + Markdown Preview --}}
            <div x-data="{ tab: 'editor' }">
                <div class="flex items-center gap-1 mb-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Contenu *</label>
                    <span class="ml-auto flex gap-1">
                        <button type="button" @click="tab = 'editor'"
                            :class="tab === 'editor' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                            class="px-3 py-1 text-xs font-medium rounded-lg transition">✏️ Éditeur</button>
                        <button type="button" @click="tab = 'preview'; $nextTick(() => renderPreview())"
                            :class="tab === 'preview' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                            class="px-3 py-1 text-xs font-medium rounded-lg transition">👁️ Aperçu</button>
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
                        <p class="text-gray-400 italic">Chargement...</p>
                    </div>
                </div>
            </div>

            {{-- Image de couverture --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Image de couverture</label>
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Catégorie</label>
                    <select name="category_id"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                        <option value="">— Aucune —</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $post->category_id) === $cat->id ? 'selected' : '' }}>{{ $cat->name_b2c }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                {{-- Tags --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tags</label>
                    <input type="text" name="tags" value="{{ old('tags', $post->tags->pluck('name')->implode(', ')) }}"
                        placeholder="php, laravel, conseil (séparés par des virgules)"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                    @error('tags')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            {{-- Statut --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Statut *</label>
                <div class="flex gap-4 flex-wrap">
                    @foreach(['draft' => 'Brouillon', 'pending' => 'En attente', 'published' => 'Publié', 'archived' => 'Archivé'] as $val => $label)
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
                <summary class="text-sm font-medium text-gray-500 dark:text-gray-400 cursor-pointer hover:text-gray-700 dark:hover:text-gray-200 transition">SEO (optionnel)</summary>
                <div class="mt-3 space-y-3 pl-4 border-l-2 border-gray-100 dark:border-gray-700">
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Meta titre</label>
                        <input type="text" name="meta_title" value="{{ old('meta_title', $post->meta_title) }}" maxlength="255"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Meta description</label>
                        <textarea name="meta_description" rows="2" maxlength="320"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">{{ old('meta_description', $post->meta_description) }}</textarea>
                    </div>
                </div>
            </details>

            {{-- Actions --}}
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition">
                    Enregistrer les modifications
                </button>
                <a href="{{ route('admin.blog') }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    Annuler
                </a>
                <a href="{{ route('blog.show', $post) }}" target="_blank"
                   class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition text-sm">
                    Voir l'article →
                </a>
            </div>
        </form>

        {{-- Suppression --}}
        <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700 flex justify-end">
            <form action="{{ route('admin.blog.destroy', $post) }}" method="POST"
                  onsubmit="return confirm('Supprimer « {{ addslashes($post->title) }} » définitivement ?')">
                @csrf @method('DELETE')
                <button type="submit" class="px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                    Supprimer l'article
                </button>
            </form>
        </div>
    </div>
</x-admin-layout>

<script>
function renderPreview() {
    const content = document.getElementById('md-content').value;
    const preview = document.getElementById('markdown-preview');
    preview.innerHTML = '<p class="text-gray-400 italic">Chargement...</p>';

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
        preview.innerHTML = '<p class="text-red-500">Erreur lors du rendu.</p>';
    });
}
</script>
