<x-guest-layout>
    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Vérifiez votre e-mail</h1>
    </div>

    <div class="px-8 py-6">
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Merci de votre inscription ! Avant de commencer, veuillez vérifier votre adresse e-mail en cliquant sur le lien que nous venons de vous envoyer.
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Si vous n'avez pas reçu l'e-mail, nous pouvons vous en envoyer un nouveau.
        </p>

        @if (session('status') == 'verification-link-sent')
        <div class="mt-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg text-sm text-green-700 dark:text-green-400">
            Un nouveau lien de vérification a été envoyé à votre adresse e-mail.
        </div>
        @endif

        {{-- TASK-1716 — renoncer, ici aussi.

             C'est la population qui en a le plus besoin : une adresse mal
             saisie rend le compte inutilisable, et comme la fermeture
             RESERVE l'adresse (arbitrage MASTER), repartir proprement suppose
             de pouvoir fermer ce compte-la. La page de profil reste
             atteignable pour un compte non verifie — `RequireVerifiedEmail`
             ouvre `profile.*` a dessein — mais il faudrait savoir y aller.

             `<details>` natif : layout INVITE, Alpine absent. Replie par
             defaut, la page doit d'abord parler de verification.

             Textes LOCALISES, alors que le reste de cette page est en dur :
             on ne recopie pas une dette qu'on a deja refuse de recopier en
             TASK-1714. --}}
        <details class="mt-6" @if($errors->userDeletion->isNotEmpty()) open @endif>
            <summary class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 underline cursor-pointer list-none"
                     data-marqueur="ouvrir-fermeture-depuis-verification">
                {{ __('profile.close_account') }}
            </summary>

            <div class="mt-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('profile.close_account_warning') }}
                </p>

                <form method="POST" action="{{ route('account.close') }}" class="mt-4">
                    @csrf
                    @method('delete')

                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('profile.close_account_password') }}
                    </label>
                    <input type="password"
                           id="password"
                           name="password"
                           autocomplete="current-password"
                           class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-red-500 focus:ring-red-500 text-sm">

                    @error('password', 'userDeletion')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <label for="erasure" class="mt-4 flex items-start gap-3 cursor-pointer">
                        {{-- Jamais `checked` : arbitrage MASTER. --}}
                        <input type="checkbox"
                               id="erasure"
                               name="erasure"
                               value="1"
                               data-marqueur="demande-effacement"
                               class="mt-0.5 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-red-600 focus:ring-red-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            {{ __('profile.close_account_erasure') }}
                        </span>
                    </label>

                    <p class="mt-2 ms-7 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('profile.close_account_erasure_help') }}
                    </p>

                    <button type="submit"
                            class="mt-4 inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold uppercase tracking-widest rounded-md transition"
                            data-marqueur="confirmer-fermeture-depuis-verification">
                        {{ __('profile.close_account_submit') }}
                    </button>
                </form>
            </div>
        </details>

        <div class="mt-6 flex items-center justify-between gap-4">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit"
                        class="py-2 px-4 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg shadow-sm transition">
                    Renvoyer l'e-mail de vérification
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 underline transition">
                    Se déconnecter
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
