<x-admin-layout title="Modifier le tag">
    <div class="mb-6">
        <a href="{{ route('admin.tags', request()->only('organization_id')) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour aux tags</a>
    </div>

    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.tags.update', $tag) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
            @csrf @method('PUT')

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $tag->name) }}" required
                       class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 @error('name') border-red-500 @enderror">
                @error('name')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="slug" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Slug</label>
                <input type="text" id="slug" name="slug" value="{{ old('slug', $tag->slug) }}"
                       class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm font-mono focus:ring-2 focus:ring-indigo-500 @error('slug') border-red-500 @enderror">
                <p class="mt-1 text-xs text-gray-400">Laissez vide pour générer automatiquement depuis le nom.</p>
                @error('slug')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Informations</h3>
                <dl class="space-y-1 text-sm">
                    <dt class="text-gray-400 inline">Organisation :</dt>
                    <dd class="inline text-gray-900 dark:text-gray-100">{{ $tag->organization?->name ?? 'Globale' }}</dd>
                    <br>
                    <dt class="text-gray-400 inline">Blog :</dt>
                    <dd class="inline {{ $tag->blog_posts_count > 0 ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-400' }}">{{ $tag->blog_posts_count }} article(s)</dd>
                    <br>
                    <dt class="text-gray-400 inline">Services :</dt>
                    <dd class="inline {{ $tag->services_count > 0 ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-400' }}">{{ $tag->services_count }} service(s)</dd>
                </dl>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
                    Enregistrer
                </button>
                <a href="{{ route('admin.tags', request()->only('organization_id')) }}" class="px-5 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
