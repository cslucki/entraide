<x-guest-layout>
    @php
        $isOrg = !is_null($currentOrganization);
        $registerAction = $isOrg ? route('organization.register', $currentOrganization) : route('register');
        $loginLink = $isOrg ? route('organization.login', $currentOrganization) : route('login');
    @endphp

    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $isOrg ? __('auth.register_title_org', ['name' => $currentOrganization->name]) : __('auth.register_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $isOrg ? __('auth.register_subtitle_org', ['name' => $currentOrganization->name]) : __('auth.register_subtitle') }}</p>
    </div>

    <div class="px-8 py-6">
        <form method="POST" action="{{ $registerAction }}">
            @csrf
            <input type="hidden" name="ref" value="{{ $ref ?? '' }}">

            <div class="space-y-5">
                <!-- Prénom -->
                <div>
                    <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.first_name_label') }}
                    </label>
                    <input id="first_name" type="text" name="first_name" value="{{ old('first_name') }}"
                           required autofocus autocomplete="given-name"
                           placeholder="{{ __('auth.first_name_placeholder') }}"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('first_name')" class="mt-1.5" />
                </div>

                <!-- Nom -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.name_label') }}
                    </label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}"
                           required autocomplete="family-name"
                           placeholder="{{ __('auth.name_placeholder') }}"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>

                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.email_label') }}
                    </label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           required autocomplete="username"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>

                <!-- Téléphone -->
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.phone_label') }}
                    </label>
                    <input id="phone" type="tel" name="phone" value="{{ old('phone') }}"
                           required autocomplete="tel"
                           placeholder="{{ __('auth.phone_placeholder') }}"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                </div>

                <!-- Pays -->
                <div>
                    <label for="country_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.country_label') }}
                    </label>
                    <select id="country_code" name="country_code" required
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                        <option value="">{{ __('auth.country_placeholder') }}</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country->code }}" {{ old('country_code') == $country->code ? 'selected' : '' }}>
                                {{ $country->getLocalizedName() }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('country_code')" class="mt-1.5" />
                </div>

                <!-- Mot de passe -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.password_label') }}
                    </label>
                    <input id="password" type="password" name="password"
                           required autocomplete="new-password"
                           placeholder="{{ __('auth.password_placeholder') }}"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>

                <!-- Confirmation mot de passe -->
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.password_confirm_label') }}
                    </label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           required autocomplete="new-password"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1.5" />
                </div>

                <!-- Bouton inscription -->
                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('auth.register_button') }}
                </button>
            </div>
        </form>
    </div>

    <!-- Lien connexion -->
    <div class="px-8 py-5 bg-gray-50 dark:bg-gray-700/40 border-t border-gray-100 dark:border-gray-700 text-center">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('auth.has_account') }}
            <a href="{{ $loginLink }}" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                {{ __('auth.login_link') }}
            </a>
        </p>
    </div>
</x-guest-layout>
