{{--
    TASK-1714 volet 2 — ce que voit un compte en attente de validation.

    L'ecran dit trois choses, et pas une de plus : ce qui est FAIT (l'adresse
    est confirmee), ce qui MANQUE (un administrateur doit valider), et ce qui
    va se passer (un email a l'approbation). Une personne qui attend a besoin
    de savoir qu'elle n'a rien oublie — pas d'un formulaire de plus.

    Aucun bouton qui ferait AVANCER l'attente : il n'y a rien a faire de son
    cote pour cela. Seules les deux sorties sont offertes — la deconnexion,
    parce qu'enfermer quelqu'un dans une session qu'il ne peut pas quitter
    serait pire que l'attente, et depuis TASK-1716 la fermeture du compte, pour
    la meme raison poussee jusqu'au bout.

    Textes localises, contrairement a `verify-email.blade.php` qui les porte en
    dur — on ne recopie pas une dette.
--}}
<x-guest-layout>
    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white" data-marqueur="titre-attente-approbation">
            {{ __('auth.approval_pending_title') }}
        </h1>
    </div>

    <div class="px-8 py-6" data-marqueur="attente-approbation">
        <div class="flex items-start gap-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700">
            <span class="mt-1.5 w-2 h-2 rounded-full bg-amber-500 flex-shrink-0" aria-hidden="true"></span>
            <p class="text-sm text-amber-800 dark:text-amber-300">
                @if ($organisation)
                    {{ __('auth.approval_pending_intro_org', ['name' => $organisation->name]) }}
                @else
                    {{ __('auth.approval_pending_intro') }}
                @endif
            </p>
        </div>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('auth.approval_pending_email_done') }}
        </p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            {{ __('auth.approval_pending_next') }}
        </p>

        {{-- TASK-1716 — renoncer est la SEULE action qui s'ajoute.

             Le docblock ci-dessus disait « aucun bouton d'action : il n'y a
             RIEN a faire de son cote », et ca reste vrai de l'attente : rien
             ici ne la fait avancer. Mais en SORTIR, pour de bon, depend bien
             d'elle. Enfermer quelqu'un dans une attente qu'il ne peut ni
             accelerer ni quitter definitivement serait la meme faute que de
             lui refuser la deconnexion.

             `<details>` natif et non une modal : ce layout est le layout
             INVITE, ou Alpine est absent (mesure du volet 1 de T1714) —
             `$dispatch('open-modal')` n'y repondrait pas. Replie par defaut :
             la page doit continuer a parler d'attente d'abord, pas de depart.

             `account.close` est une route GLOBALE, et c'est ce qui rend cet
             ecran possible sans rouvrir `profile.*` : `RequireApprovedAccount`
             l'autorise par UNE entree nommee dans `ALWAYS_ALLOWED`. --}}
        <details class="mt-6 group" @if($errors->userDeletion->isNotEmpty()) open @endif>
            <summary class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 underline cursor-pointer list-none"
                     data-marqueur="ouvrir-fermeture-depuis-attente">
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
                            data-marqueur="confirmer-fermeture-depuis-attente">
                        {{ __('profile.close_account_submit') }}
                    </button>
                </form>
            </div>
        </details>

        <div class="mt-6 flex justify-end">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 underline transition">
                    {{ __('auth.logout') }}
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
