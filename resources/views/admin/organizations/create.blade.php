<x-admin-layout title="Créer une organisation">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.organizations.store') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Informations</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('name') border-red-500 @enderror">
                    @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Slug</label>
                    <input type="text" name="slug" value="{{ old('slug') }}" placeholder="auto-généré si vide"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono @error('slug') border-red-500 @enderror">
                    @error('slug')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    <p class="text-xs text-gray-400 mt-1">Lettres minuscules, chiffres et tirets uniquement.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('description') border-red-500 @enderror">{{ old('description') }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Responsable</label>
                    <select name="admin_id"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <option value="">— Aucun —</option>
                        @foreach($admins as $a)
                            <option value="{{ $a->id }}" {{ old('admin_id') == $a->id ? 'selected' : '' }}>{{ $a->name }} ({{ $a->email }})</option>
                        @endforeach
                    </select>
                    @error('admin_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Page d'accueil</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre hero</label>
                    <input type="text" name="hero_title" value="{{ old('hero_title') }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('hero_title') border-red-500 @enderror">
                    @error('hero_title')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description hero</label>
                    <textarea name="hero_description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('hero_description') border-red-500 @enderror">{{ old('hero_description') }}</textarea>
                    @error('hero_description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Couleur d'accent</label>
                    <div class="flex items-center gap-3">
                        <input type="color" name="accent_color" value="{{ old('accent_color', '#6366f1') }}"
                            class="w-10 h-10 rounded border border-gray-300 dark:border-gray-600 cursor-pointer">
                        <input type="text" name="accent_color" value="{{ old('accent_color', '#6366f1') }}"
                            class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono @error('accent_color') border-red-500 @enderror">
                    </div>
                    @error('accent_color')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Configuration</h2>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_public" value="1" {{ old('is_public') ? 'checked' : '' }}
                        class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Visible sans connexion</span>
                        <p class="text-xs text-gray-400 mt-0.5">La page d'accueil et l'explorateur sont accessibles aux visiteurs (vitrine). Les actions nécessitent toujours un compte.</p>
                    </div>
                </label>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="hidden" name="is_default" value="0">
                    <input type="checkbox" name="is_default" value="1" {{ old('is_default') ? 'checked' : '' }}
                        class="w-4 h-4 text-purple-600 rounded border-gray-300 focus:ring-purple-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Organisation par défaut</span>
                        <p class="text-xs text-gray-400 mt-0.5">Les nouveaux utilisateurs sont automatiquement rattachés à l'organisation par défaut.</p>
                    </div>
                </label>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Points de bienvenue</label>
                <input type="number" name="welcome_points" value="{{ old('welcome_points', 100) }}" min="0" max="10000"
                    class="w-32 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('welcome_points') border-red-500 @enderror">
                @error('welcome_points')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                <p class="text-xs text-gray-400 mt-1">Points offerts à chaque nouvel inscrit dans cette organisation.</p>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Langue</h2>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Langue d'affichage</label>
                    <select name="locale"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <option value="fr" {{ old('locale', 'fr') === 'fr' ? 'selected' : '' }}>Français</option>
                        <option value="en" {{ old('locale', 'fr') === 'en' ? 'selected' : '' }}>English</option>
                    </select>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Nommage des catégories</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Blog</label>
                    <select name="blog_naming"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <option value="b2b" {{ old('blog_naming', 'b2b') === 'b2b' ? 'selected' : '' }}>B2B (entreprises)</option>
                        <option value="b2c" {{ old('blog_naming', 'b2b') === 'b2c' ? 'selected' : '' }}>B2C (membres)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Transactions</label>
                    <select name="transactions_naming"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <option value="b2c" {{ old('transactions_naming', 'b2c') === 'b2c' ? 'selected' : '' }}>B2C (membres)</option>
                        <option value="b2b" {{ old('transactions_naming', 'b2c') === 'b2b' ? 'selected' : '' }}>B2B (entreprises)</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    Créer l'organisation
                </button>
                <a href="{{ route('admin.organizations') }}" class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
