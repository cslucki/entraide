{{--
    TASK-1694 bug V — UN modal pour toute la page, pose hors du tableau.

    ## Pourquoi un seul, et pourquoi ici

    Bug L avait mis le choix EN FLUX dans la ligne, pour echapper au rognage de
    l'`overflow-x-auto` du tableau (defaut mesure en TASK-1693 bug D). Ca
    marchait, mais c'etait etroit. Cyril, le 24/10 : « certes contextualise mais
    trop petit, et si on a plus de 10 messages types ce sera ingerable ».

    Un modal PAR LIGNE aurait rendu 20 copies de la meme liste de gabarits sur
    une page de 20 membres. Celui-ci est unique : la ligne lui passe la personne
    et son URL d'envoi par evenement.

    ## L'URL vient du SERVEUR, jamais du JavaScript

    Chaque bouton porte l'URL produite par `route()`. Le modal la recoit et la
    pose telle quelle dans `action`. Rien n'est concatene a partir d'un
    identifiant — un chemin recompose cote client est un chemin qu'aucun
    `route:list` ne verifie.

    ## Le filtre, et non seulement l'ascenseur

    La demande nomme le vrai probleme : « plus de 10 messages types ». Un
    ascenseur seul laisse chercher a l'oeil dans une liste longue. Le champ
    filtre par nom ; la liste reste defilante par-dessus.

    Le filtre est PUREMENT d'affichage : il masque des `<label>`, il ne retire
    aucun `<input>` du formulaire. Un gabarit filtre puis coche resterait coche
    — d'ou la remise a zero du filtre ET du choix a chaque ouverture.
--}}
<div
    x-data="{
        label: '',
        url: '',
        recherche: '',
        correspond(nom) {
            return this.recherche === ''
                || nom.toLowerCase().includes(this.recherche.toLowerCase());
        },
    }"
    x-on:send-email-context.window="
        label = $event.detail.label;
        url = $event.detail.url;
        recherche = '';
        $nextTick(() => $el.querySelectorAll('input[name=template_id]').forEach(i => i.checked = false));
    "
>
    <x-modal name="send-email" maxWidth="2xl" focusable>
        <div class="p-6" data-user-send-email-modal>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ __('admin.users_send_email_title') }}
            </h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                <span x-text="label"></span>
            </p>

            @if($emailTemplates->isEmpty())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('admin.users_send_email_none') }}
                </p>
                <a href="{{ route('admin.email-templates.create') }}"
                   class="mt-2 inline-block text-sm text-indigo-600 hover:underline">
                    {{ __('admin.users_send_email_create') }}
                </a>
                <div class="mt-6 flex justify-end">
                    <button type="button" x-on:click="$dispatch('close-modal', 'send-email')"
                            class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">
                        {{ __('admin.cancel') }}
                    </button>
                </div>
            @else
                <form method="POST" x-bind:action="url"
                      onsubmit="return confirm(this.dataset.confirm)"
                      data-confirm="{{ __('admin.users_send_email_confirm_generic') }}"
                      class="mt-4">
                    @csrf

                    {{-- Le filtre n'apparait que quand il sert : au-dela de 8
                         gabarits, chercher a l'oeil devient penible. En dessous,
                         un champ de plus est du bruit. --}}
                    @if($emailTemplates->count() > 8)
                        <input type="search" x-model="recherche"
                               placeholder="{{ __('admin.users_send_email_filter') }}"
                               class="w-full mb-3 px-3 py-2 text-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 focus:ring-indigo-500"
                               data-user-send-email-filter>
                    @endif

                    <div class="max-h-80 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700 rounded-lg border border-gray-200 dark:border-gray-700">
                        @foreach($emailTemplates as $_tpl)
                            <label class="flex items-start gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                   x-show="correspond(@js($_tpl->name))">
                                <input type="radio" name="template_id" value="{{ $_tpl->id }}"
                                       required
                                       class="mt-1 border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                                       data-user-send-email-choice>
                                <span>
                                    <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $_tpl->name }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $_tpl->subject }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" x-on:click="$dispatch('close-modal', 'send-email')"
                                class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">
                            {{ __('admin.cancel') }}
                        </button>
                        <button type="submit"
                                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-md transition"
                                data-user-send-email-submit>
                            {{ __('admin.users_send_email_submit') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </x-modal>
</div>
