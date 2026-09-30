{{--
    TASK-1659, deuxieme etape du parcours d'invitation. La personne est deja
    connectee : ce n'est pas un ecran d'authentification, c'est le moment ou
    elle choisit le mot de passe qui lui permettra de revenir.
--}}
<x-guest-layout>
    <div class="mx-auto w-full max-w-md px-4 py-10">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="p-6 sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                    {{ $organization?->name }}
                </p>

                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {{ __('organization_invitations.password_title') }}
                </h1>

                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('organization_invitations.password_intro') }}
                </p>

                <form method="POST" action="{{ route('invitation.password.store') }}" class="mt-6 space-y-5">
                    @csrf

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('organization_invitations.password_label') }}
                        </label>
                        <input id="password" type="password" name="password" required autofocus autocomplete="new-password"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        @error('password')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ __('organization_invitations.password_confirm_label') }}
                        </label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>

                    <button type="submit"
                            class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ __('organization_invitations.password_cta') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-guest-layout>
