{{--
    TASK-1716 — « Fermer mon compte », et non plus « Supprimer le compte ».

    Le libelle change parce que le COMPORTEMENT change. L'ancien formulaire
    promettait un effacement definitif et appelait `$user->delete()` nu : depuis
    TASK-1635, la FK RESTRICT de `point_ledger` faisait lever un 500 pour tout
    compte inscrit, puisque chaque inscription y ecrit un bonus de bienvenue. La
    personne etait deconnectee, voyait une page d'erreur, et son compte restait
    la. Promettre moins et le tenir vaut mieux.

    L'avertissement dit maintenant ce qui se passe VRAIMENT : desactivation
    immediate, deconnexion, acces revoques, profil invisible — et contributions
    CONSERVEES. Laisser « toutes vos données seront définitivement effacées »
    au-dessus d'une action qui conserve tout serait un mensonge d'interface.

    La case a cocher est la seule nouveaute d'ecran. Elle n'est PAS prechee
    (arbitrage MASTER) : demander l'effacement de ses donnees doit etre un geste,
    pas un defaut qu'on subit. Et elle depose une DEMANDE — le texte d'aide le
    dit, avec la raison pour laquelle tout n'est pas toujours effacable.
--}}
<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('profile.close_account') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('profile.close_account_warning') }}
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-account-closure')"
        data-marqueur="ouvrir-fermeture-compte"
    >{{ __('profile.close_account') }}</x-danger-button>

    <x-modal name="confirm-account-closure" :show="$errors->userDeletion->isNotEmpty()" focusable>
        {{-- `account.close` : route GLOBALE et unique (`routes/auth.php`). Elle
             remplace les DEUX anciennes declarations `profile.destroy` — forme
             nue et forme prefixee par l'Organization — pour une action qui n'a
             aucune raison d'etre scopee. C'est aussi ce qui permet a la page
             d'attente d'approbation d'offrir la meme fermeture sans rouvrir
             `profile.*` (voir `RequireApprovedAccount::ALWAYS_ALLOWED`). --}}
        <form method="post" action="{{ route('account.close') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ __('profile.close_account_confirm') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('profile.close_account_instructions') }}
            </p>

            <div class="mt-6">
                <x-input-label for="password" :value="__('profile.close_account_password')" class="sr-only" />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    placeholder="{{ __('profile.close_account_password') }}"
                />

                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-5">
                <label for="erasure" class="flex items-start gap-3 cursor-pointer">
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

                <x-input-error :messages="$errors->userDeletion->get('erasure')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('profile.cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3" data-marqueur="confirmer-fermeture-compte">
                    {{ __('profile.close_account_submit') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
