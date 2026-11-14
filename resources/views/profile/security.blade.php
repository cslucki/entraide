<x-app-layout>
    <x-slot name="title">{{ __('profile.security_title') }}</x-slot>

    {{-- TASK-1824 — meme patron que `profile/edit.blade.php` : barre du haut
         standard, retour SCOPE au profil (jamais `route('profile.edit')` nu,
         qui ferait sortir du tenant courant). --}}
    <x-page-topbar :back="organizationScopedUrl('profile.edit', 'organization.profile.edit')"
                   :title="__('profile.security_title')"
                   :label="__('profile.security_back_to_profile')" />

    <x-page-container>
        <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
            {{ __('profile.security_intro') }}
        </p>

        <div class="space-y-6">
            {{-- A. Modifier mon mot de passe --}}
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            {{-- B. Securite et connexions — comptes lies, Organization active --}}
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.account-access-form')
                </div>
            </div>

            {{-- C. Fermer mon compte — visuellement separee (bordure rouge),
                 jamais au milieu des operations ordinaires ci-dessus. --}}
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg border-2 border-red-200 dark:border-red-900/50">
                <div class="max-w-xl">
                    @include('profile.partials.close-account-form')
                </div>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
