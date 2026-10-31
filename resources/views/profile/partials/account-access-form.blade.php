{{--
    TASK-1734 — l'acces aux parcours AVANCES de comptes, relegues ici.

    Le menu avatar porte desormais « Mes organisations » (bascule en un clic,
    premiere association par email). Les deux ecrans de T1730/T1731 restent
    necessaires pour ce que l'email ne couvre pas — lier deux comptes
    d'adresses DIFFERENTES, basculer vers un compte non liste, revoquer — et
    c'est ici, dans les reglages, qu'ils vivent maintenant.
--}}
<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('profile.account_access_title') }}
        </h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('profile.account_access_intro') }}
        </p>
    </header>

    <div class="mt-4 flex flex-wrap gap-3">
        <a href="{{ route('account.links') }}" data-nav-account-links
           class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
            {{ __('navigation.linked_accounts') }}
        </a>
        <a href="{{ route('account.switch') }}" data-nav-account-switch
           class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
            {{ __('navigation.switch_account') }}
        </a>
    </div>
</section>
