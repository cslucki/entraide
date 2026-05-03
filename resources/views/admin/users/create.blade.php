<x-admin-layout title="Créer un utilisateur">
    <div class="max-w-xl">
        <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Informations du compte</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom</label>
                    <input type="text" name="name" value="{{ old('name') }}"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500"
                        required maxlength="255">
                    @error('name')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email') }}"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500"
                        required maxlength="255">
                    @error('email')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mot de passe</label>
                    <input type="password" name="password"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500"
                        required minlength="8">
                    @error('password')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirmer le mot de passe</label>
                    <input type="password" name="password_confirmation"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500"
                        required minlength="8">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Points de départ</label>
                    <input type="number" name="points" value="{{ old('points', 0) }}" min="0"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500"
                        required>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Si supérieur à 0, une entrée welcome_bonus sera ajoutée au ledger.</p>
                    @error('points')
                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" name="is_admin" value="1" {{ old('is_admin') ? 'checked' : '' }}
                            class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Droits administrateur</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <a href="{{ route('admin.users') }}"
                    class="text-sm text-gray-500 dark:text-gray-400 hover:underline">
                    Annuler
                </a>
                <button type="submit"
                    class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    Créer l'utilisateur
                </button>
            </div>
        </form>
    </div>
</x-admin-layout>
