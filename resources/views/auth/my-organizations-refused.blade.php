{{--
    TASK-1734 — LE refus generique de « Mes organisations ».

    Une seule page pour tous les motifs (le journal, lui, est precis) : ces
    POST partent du menu avatar, il n'y a aucun formulaire derriere ou poser
    une erreur de validation. Meme discipline de non-enumeration que T1728,
    T1730 et T1731.
--}}
<x-app-layout>
    <div class="max-w-xl mx-auto px-4 py-12" data-myorg-refused>
        <div class="rounded-lg border border-gray-200 bg-white p-8 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('auth.myorg_refused_title') }}
            </h1>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                {{ $message }}
            </p>
            <a href="{{ route('profile.edit') }}" class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                {{ __('auth.myorg_back') }}
            </a>
        </div>
    </div>
</x-app-layout>
