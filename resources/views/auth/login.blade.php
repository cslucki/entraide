<x-guest-layout>
    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Connexion</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1"></p>
    </div>

    <div class="px-8 py-6">
        <x-auth-session-status class="mb-5" :status="session('status')" />

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="space-y-5">
                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Adresse e-mail
                    </label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           required autofocus autocomplete="username"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>

                <!-- Mot de passe -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Mot de passe
                        </label>
                        @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}"
                           class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                            Mot de passe oublié ?
                        </a>
                        @endif
                    </div>
                    <input id="password" type="password" name="password"
                           required autocomplete="current-password"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>

                <!-- Se souvenir de moi -->
                <div>
                    <label for="remember_me" class="inline-flex items-center gap-2 cursor-pointer">
                        <input id="remember_me" type="checkbox" name="remember"
                               class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:bg-gray-700">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Se souvenir de moi</span>
                    </label>
                </div>

                <!-- Bouton connexion -->
                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Se connecter
                </button>
            </div>
        </form>
    </div>

    <!-- Lien inscription -->
    <div class="px-8 py-5 bg-gray-50 dark:bg-gray-700/40 border-t border-gray-100 dark:border-gray-700 text-center">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Pas encore de compte ?
            <a href="{{ route('register') }}" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                Créer un compte
            </a>
        </p>
    </div>
</x-guest-layout>
