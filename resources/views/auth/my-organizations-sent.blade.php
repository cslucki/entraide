{{--
    TASK-1734 — « un email vient de vous etre envoye ».

    Atteint par redirection apres l'envoi (PRG : rafraichir ne renvoie pas
    d'email). L'ecran nomme l'organisation visee et l'adresse — les deux
    appartiennent a la personne authentifiee qui vient de les demander, il
    n'y a rien a taire ici. AUCUN champ de formulaire : rien a remplir,
    rien que Chrome puisse vouloir remplir.
--}}
<x-app-layout>
    <div class="max-w-xl mx-auto px-4 py-12" data-myorg-sent>
        <div class="rounded-lg border border-gray-200 bg-white p-8 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('auth.myorg_sent_title') }}
            </h1>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                {{ __('auth.myorg_sent_body', ['organization' => $organizationName, 'email' => $email]) }}
            </p>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('auth.myorg_sent_once') }}
            </p>
            <a href="{{ url()->previous() }}" class="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                {{ __('auth.myorg_back') }}
            </a>
        </div>
    </div>
</x-app-layout>
