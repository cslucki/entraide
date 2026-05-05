<x-guest-layout>
    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Confirmation requise</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Cette zone est sécurisée. Veuillez confirmer votre mot de passe avant de continuer.
        </p>
    </div>

    <div class="px-8 py-6">
        <form method="POST" action="{{ route('password.confirm') }}">
            @csrf

            <div class="space-y-5">
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Mot de passe
                    </label>
                    <input id="password" type="password" name="password"
                           required autocomplete="current-password"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>

                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Confirmer
                </button>
            </div>
        </form>
    </div>
</x-guest-layout>
