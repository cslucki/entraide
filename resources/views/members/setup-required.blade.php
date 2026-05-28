<x-app-layout>
    <div class="max-w-2xl mx-auto px-4 py-20 text-center">

        <div class="mb-6 text-5xl">🗄️</div>

        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-3">
            Base de données à initialiser
        </h1>

        <p class="text-gray-500 dark:text-gray-400 leading-relaxed mb-8">
            L'annuaire des membres n'est pas encore disponible car la base de données
            locale ne contient pas d'organisation configurée.
        </p>

        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-6 text-left text-sm text-gray-600 dark:text-gray-400 space-y-2 border border-gray-200 dark:border-gray-700">
            <p class="font-semibold text-gray-800 dark:text-gray-200">Pour initialiser l'environnement local :</p>
            <ol class="list-decimal list-inside space-y-1">
                <li>Synchroniser la base de production via <code class="text-indigo-600 dark:text-indigo-400">mirror-import</code></li>
                <li>Exécuter les migrations locales : <code class="text-indigo-600 dark:text-indigo-400">php artisan migrate</code></li>
                <li>Injecter les comptes QA : <code class="text-indigo-600 dark:text-indigo-400">QaAccountsSeeder</code></li>
                <li>Vider le cache : <code class="text-indigo-600 dark:text-indigo-400">php artisan optimize:clear</code></li>
            </ol>
        </div>

    </div>
</x-app-layout>
