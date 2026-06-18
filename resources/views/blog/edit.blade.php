<x-app-layout>
    <x-slot name="title">Modifier — {{ $post->title }}</x-slot>

    <div class="max-w-3xl mx-auto px-4 py-8">

        <div class="mb-6">
            <a href="{{ route('blog.show', $post) }}" class="text-sm text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400">← Retour à l'article</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">Modifier l'article</h1>

            <form action="{{ route('blog.update', $post) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                @csrf @method('PUT')

                @if($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">
                    <p class="font-semibold">Impossible d’enregistrer l’article. Merci de corriger les champs indiqués.</p>
                </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre *</label>
                    <input type="text" name="title" value="{{ old('title', $post->title) }}"
                        class="w-full px-3 py-2 border @error('title') border-red-500 ring-1 ring-red-500 dark:border-red-500 @else border-gray-300 dark:border-gray-600 @enderror rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    @error('title')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Résumé</label>
                    <textarea name="summary" rows="2" maxlength="500"
                        class="w-full px-3 py-2 border @error('summary') border-red-500 ring-1 ring-red-500 dark:border-red-500 @else border-gray-300 dark:border-gray-600 @enderror rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">{{ old('summary', $post->summary) }}</textarea>
                    @error('summary')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contenu *</label>
                    <x-blog-editor
                        name="content"
                        :value="old('content', $post->content)"
                        :post-id="$post->id"
                        :invalid="$errors->has('content')"
                    />
                    @error('content')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div x-data="{ preview: null }">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Image de couverture</label>
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
                        <label for="remove_image" class="text-sm text-gray-500 dark:text-gray-400">Supprimer l'image actuelle</label>
                    </div>
                    @endif
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Catégorie</label>
                    <select name="category_id"
                        class="w-full px-3 py-2 border @error('category_id') border-red-500 ring-1 ring-red-500 dark:border-red-500 @else border-gray-300 dark:border-gray-600 @enderror rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                        <option value="">— Aucune —</option>
                        @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $post->category_id) === $cat->id ? 'selected' : '' }}>{{ $cat->displayName('blog') }}</option>
                        @endforeach
                    </select>
                    @error('category_id')<p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tags</label>
                    <input type="text" name="tags" value="{{ old('tags', $post->tags->pluck('name')->implode(', ')) }}"
                        placeholder="php, laravel, conseil (séparés par des virgules)"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm">
                </div>

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

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Statut *</label>
                    <div class="flex gap-4">
                        @foreach(['draft' => 'Brouillon', 'pending' => 'En attente', 'published' => 'Publié', 'archived' => 'Archivé'] as $val => $label)
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
                        Enregistrer les modifications
                    </button>
                    <a href="{{ route('blog.show', $post) }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        Annuler
                    </a>
                </div>
            </form>

            {{-- Formulaire de suppression EN DEHORS du formulaire principal --}}
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 flex justify-end">
                <form action="{{ route('blog.destroy', $post) }}" method="POST"
                      onsubmit="return confirm('Supprimer cet article définitivement ?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition">
                        Supprimer l'article
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
