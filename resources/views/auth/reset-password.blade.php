<x-guest-layout>
    @php
        $isOrg = !is_null($currentOrganization);
        $formAction = $isOrg ? route('organization.password.store', $currentOrganization) : route('password.store');
    @endphp

    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('auth.reset_password_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('auth.reset_password_subtitle') }}</p>
    </div>

    <div class="px-8 py-6">
        <form method="POST" action="{{ $formAction }}">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="space-y-5">
                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.email_label') }}
                    </label>
                    <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}"
                           required autofocus autocomplete="username"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>

                <!-- Nouveau mot de passe -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.new_password_label') }}
                    </label>
                    <input id="password" type="password" name="password"
                           required autocomplete="new-password"
                           placeholder="{{ __('auth.password_placeholder') }}"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>

                <!-- Confirmation -->
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.password_confirm_label') }}
                    </label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           required autocomplete="new-password"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1.5" />
                </div>

                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('auth.reset_password_button') }}
                </button>
            </div>
        </form>
    </div>
</x-guest-layout>
