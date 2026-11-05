@props(['capacite' => null, 'plancher' => null])

{{--
    TASK-1756 §6.3 — les deux champs de l'ATELIER.

    Replies par defaut : la tres grande majorite des annonces restent
    individuelles, et imposer deux champs a tout le monde pour servir une
    minorite aurait alourdi le formulaire le plus utilise du produit.

    Le PLANCHER n'a de sens qu'une fois la capacite choisie : le montrer
    d'emblee ferait poser une question dont la reponse ne sert a rien.

    L'apercu du prix est calcule EN DIRECT, parce que c'est la seule facon de
    rendre la regle comprehensible : « 45 min, a partir de 3 » ne dit rien ;
    « chacun paiera au maximum 15 min » dit tout.
--}}
<div class="mt-6" x-data="{
        groupe: {{ $capacite ? 'true' : 'false' }},
        capacite: {{ (int) ($capacite ?? 4) }},
        plancher: {{ (int) ($plancher ?? 2) }},
        /*
         * `points` est une DONNEE, pas un getter qui lit le DOM.
         *
         * Defaut trouve en recette : un getter faisant
         * `document.querySelector('[name=points_cost]').value` ne declenche
         * AUCUNE reactivite — Alpine ne recalcule que sur ses propres
         * proprietes. L'apercu restait donc fige sur la premiere valeur lue :
         * on passait de 45 a 60 minutes et il annoncait toujours 15.
         *
         * Muet, et trompeur : l'aidant croyait avoir compris la regle.
         */
        points: 0,
        /* `Math.ceil` : le MEME arrondi que `Atelier::part()` cote serveur. Un
           apercu qui annoncerait 11 la ou le serveur facture 12 serait pire que
           pas d'apercu du tout. */
        get partMax() {
            const diviseur = Math.max(1, Math.min(this.plancher, this.capacite));
            return Math.ceil(this.points / diviseur);
        },
        lirePoints(champ) {
            this.points = parseInt(champ?.value || '0', 10) || 0;
        },
     }"
     x-init="
        const champ = document.querySelector('[name=points_cost]');
        lirePoints(champ);
        champ?.addEventListener('input', () => lirePoints(champ));
     "
     {{-- Baisser la capacite sous le plancher laissait une paire INVALIDE :
          `min_participants` est valide en `lte:capacity`, donc « a partir de 5,
          jusqu'a 3 » se faisait refuser au serveur — alors que l'apercu, lui,
          bornait et affichait un nombre coherent. L'aidant voyait une regle
          comprise et recevait une erreur. On borne a la SAISIE. --}}
     x-effect="if (plancher > capacite) plancher = capacite"
     data-entraide-champs-atelier>

    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
        <input type="checkbox" x-model="groupe" data-entraide-atelier-bascule
               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600">
        {{ __('entraide.atelier.champ_label') }}
    </label>

    <p x-show="! groupe" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('entraide.atelier.champ_individuel') }}</p>

    <div x-show="groupe" x-cloak class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 p-3 dark:border-indigo-800/60 dark:bg-indigo-900/20">
        <p class="text-xs text-indigo-800 dark:text-indigo-200">{{ __('entraide.atelier.champ_aide') }}</p>

        <div class="mt-3 flex flex-wrap items-end gap-4">
            <label class="block">
                <span class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('entraide.atelier.champ_capacite') }}</span>
                {{-- `x-bind:name` vide quand on repasse en individuel : sans
                     cela, le formulaire enverrait une capacite que l'aidant
                     vient justement de retirer. --}}
                <select x-model.number="capacite" x-bind:name="groupe ? 'capacity' : ''"
                        data-entraide-atelier-capacite
                        class="mt-1 w-28 rounded-lg border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    @for($i = \App\Support\Entraide\Atelier::CAPACITE_MIN; $i <= \App\Support\Entraide\Atelier::CAPACITE_MAX; $i++)
                        <option value="{{ $i }}">{{ $i }}</option>
                    @endfor
                </select>
            </label>

            <label class="block">
                <span class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('entraide.atelier.champ_plancher') }}</span>
                {{-- Borne a la capacite : « a partir de 8 » sur un atelier de 6
                     rendrait le prix maximum inatteignable. --}}
                <select x-model.number="plancher" x-bind:name="groupe ? 'min_participants' : ''"
                        data-entraide-atelier-plancher
                        class="mt-1 w-28 rounded-lg border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    @for($i = 1; $i <= \App\Support\Entraide\Atelier::CAPACITE_MAX; $i++)
                        <option value="{{ $i }}" x-show="{{ $i }} <= capacite">{{ $i }}</option>
                    @endfor
                </select>
            </label>
        </div>

        <p class="mt-3 text-xs text-indigo-900 dark:text-indigo-100" data-entraide-atelier-apercu>
            {{ __('entraide.atelier.apercu_avant') }}
            <strong x-text="partMax"></strong>
            {{ __('entraide.atelier.apercu_apres') }}
        </p>
    </div>
</div>
