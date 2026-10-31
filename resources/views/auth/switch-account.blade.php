{{--
    TASK-1730 (A5a) — passer a SON autre compte, dans une autre Organization.

    `x-guest-layout` et non `x-app-layout`, delibere : l'ecran annonce une
    DECONNEXION du compte courant. L'encadrer de la navigation du tenant qu'on
    s'apprete a quitter donnerait un repere qui va cesser d'etre vrai.

    La liste des organisations ne dit RIEN de l'endroit ou cette personne
    possede un compte : ce sont les organisations publiques et actives, les
    memes que propose deja le selecteur de connexion. C'est le mot de passe, et
    lui seul, qui prouve qu'un compte existe et qu'on le controle (arbitrage
    MASTER D3).
--}}
<x-guest-layout>
    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ __('auth.switch_title') }}
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            {{ __('auth.switch_intro') }}
        </p>
    </div>

    <div class="px-8 py-6">
        <form method="POST" action="{{ route('account.switch.store') }}">
            @csrf

            <div class="space-y-5">
                <div>
                    <label for="organization" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.switch_organization_label') }}
                    </label>
                    <select id="organization" name="organization" required
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                        <option value="">{{ __('auth.switch_organization_placeholder') }}</option>
                        @foreach($organizationsProposables as $proposable)
                            @continue($organizationCourante && $proposable->getKey() === $organizationCourante->getKey())
                            <option value="{{ $proposable->slug }}" {{ old('organization') === $proposable->slug ? 'selected' : '' }}>
                                {{ $proposable->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('organization')" class="mt-1.5" />
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.switch_password_label') }}
                    </label>
                    <input id="password" type="password" name="password"
                           required autocomplete="off"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>

                <button type="submit"
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('auth.switch_submit') }}
                </button>
            </div>
        </form>
    </div>
</x-guest-layout>
