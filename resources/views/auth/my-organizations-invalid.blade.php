{{--
    TASK-1734 — jeton inconnu, expire ou deja consomme : INDISCERNABLES.

    L'ecran ne nomme AUCUNE organisation et ne distingue aucun des trois
    cas : dire lequel renseignerait un porteur de lien qui n'est pas la
    bonne personne.
--}}
<x-app-layout>
    <div class="max-w-xl mx-auto px-4 py-12" data-myorg-invalid>
        <div class="rounded-lg border border-gray-200 bg-white p-8 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('auth.myorg_invalid_title') }}
            </h1>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                {{ __('auth.myorg_invalid_body') }}
            </p>
            <a href="{{ route('profile.edit') }}" class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                {{ __('auth.myorg_back') }}
            </a>
        </div>
    </div>
</x-app-layout>
