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
    <x-page-topbar :back="organizationScopedUrl('dashboard', 'organization.dashboard')"
                   :title="__('profile.edit_title')"
                   :label="__('dashboard.back_to_dashboard')" />

    <x-page-container>

        @include('partials.profile-reminder')

        <div class="space-y-6">
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

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
