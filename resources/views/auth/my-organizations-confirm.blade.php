{{--
    TASK-1734 — la page du lien de l'email : UN bouton, rien d'autre.

    Le GET qui rend cette page n'a PAS consomme le jeton : les scanners de
    boite prechargent les liens, et un GET consommant tuerait le bouton
    avant qu'un humain ne le voie. C'est le POST ci-dessous qui consomme —
    une fois, atomiquement.

    AUCUN champ de saisie : le parcours nominal de « Mes organisations » ne
    rend jamais un input de mot de passe, et c'est un critere d'acceptation.
--}}
<x-app-layout>
    <div class="max-w-xl mx-auto px-4 py-12" data-myorg-confirm>
        <div class="rounded-lg border border-gray-200 bg-white p-8 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('auth.myorg_confirm_title') }}
            </h1>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                {{ __('auth.myorg_confirm_body', ['organization' => $organizationName]) }}
            </p>
            <form method="POST" action="{{ route('my-organizations.confirm.store', ['token' => $token]) }}" class="mt-6">
                @csrf
                <button type="submit" data-myorg-confirm-button
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('auth.myorg_confirm_submit') }}
                </button>
            </form>
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                {{ __('auth.myorg_confirm_not_you') }}
            </p>
        </div>
    </div>
</x-app-layout>
