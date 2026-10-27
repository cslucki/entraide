{{--
    TASK-1714 volet 1 — choisir son organisation, en menu deroulant.

    ## Un `<select>`, et non une modale (arbitrage Cyril, 27/10)

    La premiere version ouvrait une modale depuis un lien sous le formulaire.
    Cyril : « juste un menu deroulant apres le champ Prenom ». C'est plus juste
    — le choix de l'organisation fait partie du formulaire, il n'est pas une
    digression — et cela supprime au passage toute la mecanique d'ouverture.

    Le menu reste visible sur TOUTES les organisations : se tromper d'adresse
    est au moins aussi frequent que viser juste.

    AUCUN choix par defaut (arbitrage Cyril, 27/10) : la personne DESIGNE son
    organisation. Une valeur pre-selectionnee se laisse traverser sans etre lue
    — c'est tout l'interet d'un placeholder vide. L'organisation courante reste
    identifiable, suffixee « vous etes ici », mais elle ne s'impose plus.

    ## Il CONFIRME, il ne selectionne pas

    C'est la clef, et c'est ce qui permet de rendre le choix OBLIGATOIRE sans
    ouvrir de porte.

    Le champ est bien soumis (`name="organisation_confirmee"`), mais sa seule
    valeur acceptee cote serveur est le slug de l'organisation que l'URL
    designe deja : `Rule::in([$organization->slug])`. Poster autre chose est
    une erreur de validation, **jamais un changement de tenant**.
    L'organisation reste determinee par l'URL, exactement comme avant — la
    frontiere de securite ne bouge pas d'un pouce.

    Ce que `required` achete, du coup : la personne ne peut plus traverser le
    formulaire sans avoir regarde ou elle s'inscrit.

    La VALEUR d'une option est le slug ; l'URL vit dans un `data-url`, lue par
    la navigation seule. Une URL soumise serait une URL a valider.

    Changer d'organisation reste une NAVIGATION : le menu emmene sur la page
    d'inscription choisie, et c'est elle qui, a son tour, acceptera son propre
    slug. Sans JavaScript la degradation est propre — choisir une autre
    organisation produit une erreur de validation lisible, jamais une
    inscription au mauvais endroit.

--}}
@props(['organizations', 'courante' => null])

@if ($organizations->count() > 1)
    <div data-marqueur="selecteur-organisation">
        <label for="organisation-inscription" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ __('auth.organization_choice_label') }}
        </label>

        <select id="organisation-inscription"
                name="organisation_confirmee"
                required
                data-marqueur="menu-organisations"
                data-slug-courant="{{ $courante?->slug }}"
                class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
            {{-- Aucun choix par defaut (arbitrage Cyril, 27/10) : la personne
                 DESIGNE son organisation, elle n'herite pas de celle ou le lien
                 l'a menee. Une valeur pre-selectionnee se laisse traverser sans
                 etre lue. --}}
            <option value="" data-marqueur="organisation-aucune" @selected(old('organisation_confirmee') === null)>
                {{ __('auth.organization_choice_placeholder') }}
            </option>
            @foreach ($organizations as $organisation)
                {{-- La VALEUR est le slug — c'est lui que le serveur confirme.
                     L'URL vit dans un `data-`, lue par la navigation seule :
                     une URL soumise serait une URL a valider. --}}
                <option value="{{ $organisation->slug }}"
                        data-url="{{ route('organization.register', ['organization' => $organisation->slug]) }}"
                        data-marqueur="organisation-{{ $organisation->slug }}"
                        @selected(old('organisation_confirmee') === $organisation->slug)>
                    {{ $organisation->name }}@if ($courante && $courante->is($organisation)) — {{ __('auth.organization_choice_current') }}@endif
                </option>
            @endforeach
        </select>

        <x-input-error :messages="$errors->get('organisation_confirmee')" class="mt-1.5" />

        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
            {{ __('auth.organization_choice_hint') }}
        </p>
    </div>

    <script>
        (function () {
            var menu = document.getElementById('organisation-inscription');
            if (!menu) { return; }

            // Le slug de l'organisation courante : celui que le serveur
            // acceptera. Choisir une AUTRE organisation est une navigation.
            var ici = menu.dataset.slugCourant || '';

            menu.addEventListener('change', function () {
                var choix = menu.selectedOptions[0];

                if (choix && choix.value && choix.value !== ici && choix.dataset.url) {
                    window.location.href = choix.dataset.url;
                }
            });
        })();
    </script>
@endif
