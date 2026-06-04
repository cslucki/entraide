<x-admin-layout title="Éditer l'organisation">
    <div class="max-w-2xl">
        <form method="POST" action="{{ route('admin.organizations.update', $organization) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Informations</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $organization->name) }}" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('name') border-red-500 @enderror">
                    @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Slug</label>
                    <input type="text" name="slug" value="{{ old('slug', $organization->slug) }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono @error('slug') border-red-500 @enderror">
                    @error('slug')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('description') border-red-500 @enderror">{{ old('description', $organization->description) }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Page d'accueil</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Titre hero</label>
                    <input type="text" name="hero_title" value="{{ old('hero_title', $organization->hero_title) }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('hero_title') border-red-500 @enderror">
                    @error('hero_title')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description hero</label>
                    <textarea name="hero_description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('hero_description') border-red-500 @enderror">{{ old('hero_description', $organization->hero_description) }}</textarea>
                    @error('hero_description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Couleur d'accent</label>
                    <div class="flex items-center gap-3">
                        <input type="color" id="accent_color_picker" value="{{ old('accent_color', $organization->accent_color) }}"
                            class="w-10 h-10 rounded border border-gray-300 dark:border-gray-600 cursor-pointer">
                        <input type="text" name="accent_color" id="accent_color_text" value="{{ old('accent_color', $organization->accent_color) }}"
                            class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono @error('accent_color') border-red-500 @enderror">
                    </div>
                    @error('accent_color')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Points de bienvenue</label>
                    <input type="number" name="welcome_points" value="{{ old('welcome_points', $organization->welcome_points) }}" min="0" max="10000"
                        class="w-32 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('welcome_points') border-red-500 @enderror">
                    @error('welcome_points')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Identité de la plateforme</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom de la plateforme <span class="text-red-500">*</span></label>
                    <input type="text" name="platform_name" value="{{ old('platform_name', $organization->platform_name) }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('platform_name') border-red-500 @enderror"
                        required maxlength="100">
                    @error('platform_name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tagline</label>
                    <input type="text" name="platform_tagline" value="{{ old('platform_tagline', $organization->platform_tagline) }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('platform_tagline') border-red-500 @enderror"
                        maxlength="255">
                    @error('platform_tagline')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Apparence</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Mode couleur du site</label>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="global_color_mode" value="dark"
                                   class="sr-only peer"
                                   {{ old('global_color_mode', $organization->global_color_mode) === 'dark' ? 'checked' : '' }}>
                            <div class="flex flex-col items-center gap-3 p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-950/30 transition">
                                <div class="w-full h-16 rounded-lg bg-gray-900 border border-gray-700 overflow-hidden flex flex-col">
                                    <div class="h-3 bg-gray-800 border-b border-gray-700 flex items-center px-2 gap-1">
                                        <div class="w-8 h-1.5 bg-indigo-500 rounded-full"></div>
                                    </div>
                                    <div class="flex-1 p-1.5 flex flex-col gap-1">
                                        <div class="h-1.5 bg-gray-700 rounded w-3/4"></div>
                                        <div class="h-1.5 bg-gray-700 rounded w-1/2"></div>
                                    </div>
                                </div>
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Dark</p>
                                <div class="w-4 h-4 rounded-full border-2 border-gray-400 peer-checked:border-indigo-500 flex items-center justify-center">
                                    <div class="w-2 h-2 rounded-full {{ old('global_color_mode', $organization->global_color_mode) === 'dark' ? 'bg-indigo-500' : '' }}"></div>
                                </div>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="global_color_mode" value="light"
                                   class="sr-only peer"
                                   {{ old('global_color_mode', $organization->global_color_mode) === 'light' ? 'checked' : '' }}>
                            <div class="flex flex-col items-center gap-3 p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 peer-checked:border-indigo-500 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-950/30 transition">
                                <div class="w-full h-16 rounded-lg bg-white border border-gray-200 overflow-hidden flex flex-col">
                                    <div class="h-3 bg-gray-50 border-b border-gray-200 flex items-center px-2 gap-1">
                                        <div class="w-8 h-1.5 bg-indigo-500 rounded-full"></div>
                                    </div>
                                    <div class="flex-1 p-1.5 flex flex-col gap-1">
                                        <div class="h-1.5 bg-gray-200 rounded w-3/4"></div>
                                        <div class="h-1.5 bg-gray-200 rounded w-1/2"></div>
                                    </div>
                                </div>
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Light</p>
                                <div class="w-4 h-4 rounded-full border-2 border-gray-400 peer-checked:border-indigo-500 flex items-center justify-center">
                                    <div class="w-2 h-2 rounded-full {{ old('global_color_mode', $organization->global_color_mode) === 'light' ? 'bg-indigo-500' : '' }}"></div>
                                </div>
                            </div>
                        </label>
                    </div>
                    @error('global_color_mode')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Configuration</h2>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="is_default" value="0">
                        <input type="checkbox" name="is_default" value="1"
                            {{ old('is_default', $organization->is_default) ? 'checked' : '' }}
                            class="w-4 h-4 text-purple-600 rounded border-gray-300 focus:ring-purple-500">
                        <div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Organisation par défaut</span>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Une seule organisation peut être définie par défaut. Les nouveaux utilisateurs sont automatiquement rattachés à celle-ci.</p>
                        </div>
                    </label>
                </div>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="is_public" value="1"
                            {{ old('is_public', $organization->is_public) ? 'checked' : '' }}
                            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                        <div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Visible sans connexion</span>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">La page d'accueil et l'explorateur sont accessibles aux visiteurs (vitrine). Les actions nécessitent toujours un compte.</p>
                        </div>
                    </label>
                </div>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="loops_enabled" value="0">
                        <input type="checkbox" name="loops_enabled" value="1"
                            {{ old('loops_enabled', $organization->loops_enabled ?? true) ? 'checked' : '' }}
                            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                        <div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Boucles activées</span>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Quand désactivé, le lien Boucles est masqué et les routes sont bloquées pour cette organisation.</p>
                        </div>
                    </label>
                </div>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="maintenance_mode" value="0">
                        <input type="checkbox" name="maintenance_mode" value="1"
                            {{ old('maintenance_mode', $organization->maintenance_mode) ? 'checked' : '' }}
                            class="w-4 h-4 text-amber-600 rounded border-gray-300 focus:ring-amber-500">
                        <div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Mode maintenance</span>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Quand activé, seuls les admins peuvent accéder à la plateforme de cette organisation.</p>
                        </div>
                    </label>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Administration</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Responsable</label>
                    <select name="admin_id"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <option value="">— Aucun —</option>
                        @foreach($admins as $a)
                            <option value="{{ $a->id }}" {{ old('admin_id', $organization->admin_id) == $a->id ? 'selected' : '' }}>{{ $a->name }} ({{ $a->email }})</option>
                        @endforeach
                    </select>
                    @error('admin_id')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    Enregistrer
                </button>
                <a href="{{ route('admin.organizations') }}" class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    Annuler
                </a>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        document.getElementById('accent_color_picker').addEventListener('input', function(e) {
            document.getElementById('accent_color_text').value = e.target.value;
        });
        document.getElementById('accent_color_text').addEventListener('input', function(e) {
            document.getElementById('accent_color_picker').value = e.target.value;
        });
    </script>
    @endpush
</x-admin-layout>
