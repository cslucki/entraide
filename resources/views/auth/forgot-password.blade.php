<x-guest-layout>
    @php
        $isOrg = !is_null($currentOrganization);
        $formAction = $isOrg ? route('organization.password.email', $currentOrganization) : route('password.email');
        $loginLink = $isOrg ? route('organization.login', $currentOrganization) : route('login');
    @endphp

    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('auth.forgot_password_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            {{ __('auth.forgot_password_subtitle') }}
        </p>
    </div>

    <div class="px-8 py-6">
        <x-auth-session-status class="mb-5" :status="session('status')" />

        <form method="POST" action="{{ $formAction }}">
            @csrf

            <div class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.email_label') }}
                    </label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           required autofocus
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>

                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('auth.forgot_password_button') }}
                </button>
            </div>
        </form>
    </div>

    <div class="px-8 py-5 bg-gray-50 dark:bg-gray-700/40 border-t border-gray-100 dark:border-gray-700 text-center">
        <a href="{{ $loginLink }}" class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
            ← {{ __('auth.back_to_login') }}
        </a>
    </div>
</x-guest-layout>
