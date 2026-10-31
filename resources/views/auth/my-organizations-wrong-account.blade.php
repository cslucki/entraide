{{--
    TASK-1734 — jeton valide, mais la session n'est pas le compte source.

    On dit QUOI faire — ouvrir le lien depuis le compte d'ou la demande est
    partie — sans nommer ce compte ni son organisation : l'ecran est
    atteignable par quiconque tient le lien, et seule la boite sait d'ou il
    vient. Le jeton reste vivant : un GET ne brule rien.
--}}
<x-app-layout>
    <div class="max-w-xl mx-auto px-4 py-12" data-myorg-wrong-account>
        <div class="rounded-lg border border-gray-200 bg-white p-8 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('auth.myorg_wrong_account_title') }}
            </h1>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                {{ __('auth.myorg_wrong_account_body') }}
            </p>
        </div>
    </div>
</x-app-layout>
