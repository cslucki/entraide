<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('profile.password_section_heading') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('profile.password_section_description') }}
        </p>
    </header>

    {{-- TASK-1825 — confirmation premium, persistante tant que cette page reste
         affichee (le statut vient de la session flash du redirect, pas d'un
         minuteur JS). Pas de `x-data`/`x-transition` : rien a animer, rien a
         faire disparaitre. `role="status"` pour l'accessibilite, et l'icone
         porte le sens au cas ou la couleur ne serait pas percue. --}}
    @if (session('status') === 'password-updated')
        <div
            role="status"
            data-marqueur="mot-de-passe-modifie"
            class="mt-4 flex items-start gap-3 rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 px-4 py-3"
        >
            <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75l2.25 2.25 4.5-4.5m4.5 2.25a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="text-sm font-medium text-green-800 dark:text-green-200">{{ __('profile.password_updated_success') }}</p>
                <p class="mt-0.5 text-sm text-green-700 dark:text-green-300">{{ __('profile.password_updated_success_detail') }}</p>
            </div>
        </div>
    @endif

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div>
            <x-input-label for="update_password_current_password" :value="__('profile.current_password')" />
            <x-text-input id="update_password_current_password" name="current_password" type="password" class="mt-1 block w-full" autocomplete="current-password" />
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="update_password_password" :value="__('profile.new_password')" />
            <x-text-input id="update_password_password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="update_password_password_confirmation" :value="__('profile.confirm_password')" />
            <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('profile.save') }}</x-primary-button>
        </div>
    </form>
</section>
