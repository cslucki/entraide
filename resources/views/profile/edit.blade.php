<x-app-layout>
    <x-slot name="title">{{ __('profile.edit_title') }}</x-slot>

    {{-- TASK-1749 — la barre du haut standard (`x-page-topbar`).

         Le gros `<h1>` qui vivait DANS le conteneur est retire : la barre
         porte desormais le titre, et les garder tous les deux l'aurait affiche
         deux fois sur la meme page. Les paragraphes d'introduction restent —
         ce sont du CONTENU, pas un titre.

         `organizationScopedUrl()` et pas `route('dashboard')` : le retour doit
         rester dans le locataire. C'est le defaut que TASK-1697 a corrige neuf
         fois sur la seule fiche profil. --}}
    {{-- TASK-1762 point 3 — « Voir mon profil public », en haut de la page.

         L'adresse est calculee UNE fois ici : le bouton existe en deux
         exemplaires — la barre du haut ne rend rien sous `md` — et deux
         constructions d'URL finiraient par diverger. `profileRouteKey()` est
         l'autorite : le slug choisi, ou l'identifiant a defaut. --}}
    @php
        $urlProfilPublic = ($_orgSlug = currentOrganization()?->slug) && Route::has('organization.profile.show')
            ? route('organization.profile.show', ['organization' => $_orgSlug, 'user' => $user->profileRouteKey()])
            : route('profile.show', $user->profileRouteKey());
    @endphp

    <x-page-topbar :back="organizationScopedUrl('dashboard', 'organization.dashboard')"
                   :title="__('profile.edit_title')"
                   :label="__('dashboard.back_to_dashboard')">
        <x-slot name="action">
            <a href="{{ $urlProfilPublic }}" data-profile-public-link
               class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                {{ __('profile.view_public_profile') }}
            </a>
        </x-slot>
    </x-page-topbar>

    <x-page-container>

        {{-- Le meme bouton pour le telephone : `x-page-topbar` est `hidden
             md:flex`, et s'en tenir au slot l'aurait rendu invisible la ou la
             plupart des membres consultent leur profil. --}}
        <a href="{{ $urlProfilPublic }}" data-profile-public-link-mobile
           class="md:hidden mb-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            {{ __('profile.view_public_profile') }}
        </a>

        @include('partials.profile-reminder')

        <div class="space-y-6">
            {{-- TASK-1762 point 16 — ce partiel porte DESORMAIS ses propres
                 cartes, et la page ne doit donc plus l'envelopper dans une.

                 Demande de Cyril : « Informations de profil » et
                 « Présentation » doivent etre deux blocs SEPARES. Imbriques
                 dans la carte unique de la page, ils se lisaient comme deux
                 encadres d'un meme bloc. Les partiels voisins — mot de passe,
                 acces au compte, fermeture — gardent la leur. --}}
            @include('profile.partials.update-profile-information-form')

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.account-access-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.close-account-form')
                </div>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
