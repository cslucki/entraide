{{--
    TASK-1753 (§4.5 du CDC) — « Saisir une note » depuis /admin/users.

    ## Un seul pop-up pour toute la page

    Meme patron que `user-send-email-modal.blade.php` (T1694 bug V), et pour les
    memes deux raisons, toutes deux mesurees sur ce depot :

    - un modal PAR LIGNE rendrait 20 copies du meme formulaire sur une page de
      20 comptes (defaut T1694 bug L) ;
    - la colonne Actions vit dans un `overflow-x-auto` : tout positionnement
      `absolute` y est ROGNE (defaut T1693 bug D). Le pop-up est donc pose hors
      du tableau, et la ligne ne porte que son declencheur.

    ## L'URL vient du SERVEUR

    Chaque declencheur passe l'URL produite par `route()`. Rien n'est concatene
    a partir d'un identifiant : un chemin recompose cote client est un chemin
    qu'aucun `route:list` ne verifie.

    ## Le brouillon survit a une fermeture accidentelle

    `Echap` et le clic sur le fond ferment le modal — c'est le comportement du
    composant de modal partage, et il est souhaitable. Sans precaution, une note a
    moitie ecrite serait perdue sans un mot. Les textes sont donc gardes dans un
    objet indexe PAR COMPTE, le temps de vie de la page : rouvrir la meme
    personne restaure son texte, et deux personnes ne partagent jamais le leur.

    ## Ce qui est REMIS A ZERO a chaque ouverture

    Le canal, lui, ne se garde pas. Un canal choisi pour la personne precedente
    et herite en silence ecrirait un faux `last_interaction_at` chez quelqu'un
    d'autre — `CrmTimelineService::addNote()` avance cette colonne des qu'un
    canal est renseigne. C'est une vraie consequence metier, pas un champ
    decoratif.
--}}
<div
    x-data="{
        id: '',
        nom: '',
        email: '',
        url: '',
        suivi: false,
        canal: '',
        /* Indexe par compte : voir « Le brouillon survit » ci-dessus. */
        brouillons: {},
        get corps() { return this.brouillons[this.id] ?? '' },
        set corps(valeur) { this.brouillons[this.id] = valeur },
    }"
    x-on:crm-note-context.window="
        id = $event.detail.id;
        nom = $event.detail.nom;
        email = $event.detail.email;
        url = $event.detail.url;
        suivi = $event.detail.suivi;
        canal = '';
        /*
         * Le curseur est DEJA dans la zone de texte a l'ouverture.
         *
         * Volontairement PAS l'attribut `focusable` du composant de modal : il
         * focalise le PREMIER element focalisable dans l'ordre du DOM, ce qui
         * serait le bouton « Annuler » ou le selecteur de canal si le balisage
         * bouge. On nomme la cible par son marqueur.
         *
         * ## Pourquoi `$el.querySelector` et NON `$refs`
         *
         * Mesure du 05/11, en recette navigateur. Le composant de modal porte
         * SON PROPRE `x-data` : un `x-ref` pose dans son contenu s'enregistre
         * sur CE composant-la, pas sur celui-ci. `$refs.corps` valait donc
         * `undefined` et lachait un `TypeError` a chaque ouverture — le curseur
         * n'arrivait jamais dans la zone, et rien ne le disait a l'ecran.
         * C'est exactement pour cette raison que le modal email de T1694
         * emploie `$el.querySelectorAll()`.
         *
         * ## Pourquoi un delai et non `$nextTick`
         *
         * La ligne emet `crm-note-context` PUIS `open-modal` : au `$nextTick`,
         * le modal est encore `display: none` et le focus serait perdu en
         * silence. 150 ms passe apres l'ouverture, et au-dela des 100 ms que le
         * composant s'accorde lui-meme.
         *
         * L'appel est optionnel (`?.`) : plus jamais de TypeError ici, quoi
         * qu'il arrive au balisage.
         */
        setTimeout(() => $el.querySelector('[data-crm-note-body]')?.focus(), 150);
    "
>
    <x-modal name="crm-note" maxWidth="2xl">
        <div class="p-6" data-crm-note-modal>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ __('admin.crm_note_modal_title') }}
            </h2>

            {{-- Nom ET email : deux « Demo Main Member » existent dans la base de
                 recette, le nom seul ne dit pas de qui on parle. --}}
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                <span class="font-medium text-gray-700 dark:text-gray-200" x-text="nom"></span>
                <span class="text-gray-400"> · </span><span x-text="email"></span>
            </p>

            {{-- Decision N1 (Cyril, 04/11) — la note ajoute la personne au suivi si
                 besoin, et le pop-up doit le DIRE. L'encart n'apparait que lorsque
                 la phrase est vraie : sur un membre deja suivi elle serait fausse. --}}
            <div x-show="! suivi" x-cloak
                 class="mt-4 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-800/60 dark:bg-amber-900/20"
                 data-crm-note-implicit>
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-xs text-amber-800 dark:text-amber-200">
                    {{ __('admin.crm_note_implicit_follow') }}
                </p>
            </div>

            <form method="POST" x-bind:action="url" class="mt-4">
                @csrf

                {{-- `required` et `maxlength` attrapent le cas courant DANS le
                     navigateur. Ils ne suffisent pas : le layout admin ne rend ni
                     le sac `$errors` ni la directive Blade « error », donc un
                     echec de validation serveur serait MUET. C'est
                     `AdminCrmController::storeMemberNote()` qui convertit l'echec
                     en `session('error')` — la seule garde qui tienne sans
                     JavaScript.

                     NB : ecrire cette directive avec son arobase, meme DANS un
                     commentaire Blade, ouvrirait ici un bloc conditionnel jamais
                     ferme — `compileStatements()` passe AVANT `compileComments()`.
                     Mesure du 05/11, et la vue entiere refusait de compiler. --}}
                {{-- `Ctrl`/`Cmd` + `Entree` envoie : on tape, on envoie, sans
                     toucher la souris. `Entree` seul reste un retour a la ligne —
                     une note fait souvent plusieurs lignes. --}}
                <textarea name="body" rows="5" required
                          maxlength="{{ \App\Services\Crm\CrmTimelineService::MAX_NOTE_LENGTH }}"
                          x-model="corps"
                          x-on:keydown.ctrl.enter="$el.form.requestSubmit()"
                          x-on:keydown.meta.enter="$el.form.requestSubmit()"
                          placeholder="{{ __('crm.note_placeholder') }}"
                          data-crm-note-body
                          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>

                {{-- Le compteur n'apparait qu'au-dela de 4500 sur 5000. Permanent,
                     il serait du bruit sur une note de deux lignes ; absent, la
                     limite ne se decouvrirait qu'au refus. --}}
                <p x-show="corps.length > 4500" x-cloak class="mt-1 text-right text-xs"
                   x-bind:class="corps.length >= {{ \App\Services\Crm\CrmTimelineService::MAX_NOTE_LENGTH }} ? 'font-semibold text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
                   data-crm-note-counter>
                    <span x-text="corps.length"></span> / {{ \App\Services\Crm\CrmTimelineService::MAX_NOTE_LENGTH }}
                </p>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                    {{-- Facultatif, « Memo interne » par defaut. Un canal renseigne
                         avance `last_interaction_at` : la phrase qui suit n'est pas
                         une aide, c'est l'enonce d'une consequence. --}}
                    <select name="channel" x-model="canal" data-crm-note-channel
                            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">{{ __('crm.channel.none') }}</option>
                        @foreach($noteChannels as $_canal)
                            <option value="{{ $_canal }}">{{ __('crm.channel.'.$_canal) }}</option>
                        @endforeach
                    </select>
                    <span class="flex-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('crm.show.channel_hint') }}
                    </span>
                </div>

                <div class="mt-6 flex items-center justify-between gap-3">
                    <span class="hidden text-[11px] text-gray-400 sm:block dark:text-gray-500">
                        <kbd class="rounded border border-gray-300 px-1.5 py-0.5 font-sans dark:border-gray-600">Ctrl</kbd>
                        +
                        <kbd class="rounded border border-gray-300 px-1.5 py-0.5 font-sans dark:border-gray-600">{{ __('admin.crm_note_enter_key') }}</kbd>
                        {{ __('admin.crm_note_shortcut') }}
                    </span>
                    <div class="flex items-center gap-3">
                        <button type="button" x-on:click="$dispatch('close-modal', 'crm-note')"
                                class="px-4 py-2 text-sm text-gray-600 hover:underline dark:text-gray-300">
                            {{ __('admin.cancel') }}
                        </button>
                        <button type="submit"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700"
                                data-crm-note-submit>
                            {{ __('admin.crm_note_submit') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </x-modal>
</div>
