<x-admin-layout title="Modifier l'utilisateur">
    <div class="max-w-2xl mx-auto">
        <div class="flex items-center gap-3 mb-6">
            <a href="{{ route('admin.users') }}" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div class="flex items-center gap-3">
                <img src="{{ $user->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white leading-tight">{{ $user->name }}</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $user->email }} · Inscrit le {{ $user->created_at->format('d/m/Y') }}</p>
                </div>
            </div>
        </div>

        @if(session('success'))
        <div class="mb-5 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-xl text-sm text-green-700 dark:text-green-400">
            {{ session('success') }}
        </div>
        @endif

        @if($errors->any())
        <div class="mb-5 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl text-sm text-red-700 dark:text-red-400">
            <ul class="list-disc ml-4 space-y-1">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
        @endif

        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-5">
            @csrf @method('PUT')

            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-5">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Informations</h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Localisation</label>
                        <input type="text" name="location" value="{{ old('location', $user->location) }}" maxlength="100"
                               placeholder="Ville, département…"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Organisation</label>
                        <select name="organization_id"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="">— Organisation par defaut de la plateforme —</option>
                            @foreach($organizations as $organization)
                            <option value="{{ $organization->id }}" {{ old('organization_id', $user->organization_id) === $organization->id ? 'selected' : '' }}>
                                {{ $organization->name }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Présentation (bio)</label>
                    <textarea name="bio" rows="3" maxlength="500"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">{{ old('bio', $user->bio) }}</textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Site web</label>
                        <input type="url" name="website" value="{{ old('website', $user->website) }}" maxlength="255"
                               placeholder="https://…"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">LinkedIn</label>
                        <input type="url" name="linkedin_url" value="{{ old('linkedin_url', $user->linkedin_url) }}" maxlength="255"
                               placeholder="https://linkedin.com/in/…"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>
            </div>

            <!-- Droits et statut -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-3">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Droits & statut</h2>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_admin" value="1" {{ old('is_admin', $user->is_admin) ? 'checked' : '' }}
                           {{ $user->id === auth()->id() ? 'disabled' : '' }}
                           class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Super-administrateur</span>
                        <p class="text-xs text-gray-400">Accès complet à l'administration de la plateforme</p>
                    </div>
                </label>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_available" value="1" {{ old('is_available', $user->is_available) ? 'checked' : '' }}
                           class="rounded border-gray-300 dark:border-gray-600 text-green-600 focus:ring-green-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Disponible</span>
                        <p class="text-xs text-gray-400">Visible dans l'annuaire et accepte des échanges</p>
                    </div>
                </label>

                @if($user->id !== auth()->id())
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="banned" value="1" {{ old('banned', $user->banned_at ? '1' : '') ? 'checked' : '' }}
                           class="rounded border-gray-300 dark:border-gray-600 text-red-600 focus:ring-red-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Banni</span>
                        <p class="text-xs text-gray-400">L'utilisateur ne peut plus se connecter ni agir sur la plateforme</p>
                    </div>
                </label>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg shadow-sm transition">
                    Enregistrer les modifications
                </button>
                <a href="{{ route('admin.users') }}"
                   class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    Annuler
                </a>
                <a href="{{ route('profile.show', $user) }}" target="_blank"
                   class="ml-auto text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                    Voir le profil public ↗
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
