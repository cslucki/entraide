{{--
    TASK-1714 volet 1 — choisir son organisation, en menu deroulant.

    ## Un `<select>`, et non une modale (arbitrage Cyril, 27/10)

    La premiere version ouvrait une modale depuis un lien sous le formulaire.
    Cyril : « juste un menu deroulant apres le champ Prenom ». C'est plus juste
    — le choix de l'organisation fait partie du formulaire, il n'est pas une
    digression — et cela supprime au passage toute la mecanique d'ouverture.

    Le menu reste visible sur TOUTES les organisations : se tromper d'adresse
    est au moins aussi frequent que viser juste, et le menu dit de toute facon
    ou l'on se trouve.

    ## Il ne porte pas de `name`, et c'est delibere

    Ce `<select>` NAVIGUE, il ne se soumet pas. Sans `name`, il n'entre pas
    dans le POST : l'organisation d'inscription reste determinee par l'URL,
    cote serveur, et ne peut pas etre forgee par le formulaire. Un champ
    soumis ouvrirait une porte — on choisirait son organisation en modifiant
    une valeur, sans passer par sa page.

    La navigation se fait en JavaScript faute de mieux, mais la degradation est
    propre : sans JS, le menu affiche quand meme ou l'on est, et l'inscription
    sur place fonctionne normalement.

    `data-marqueur` n'est pas cosmetique : les gardes mesurent CETTE region, et
    non la page (lecon T1697).
--}}
@props(['organizations', 'courante' => null])

@if ($organizations->count() > 1)
    <div data-marqueur="selecteur-organisation">
        <label for="organisation-inscription" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ __('auth.organization_choice_label') }}
        </label>

        <select id="organisation-inscription"
                data-marqueur="menu-organisations"
                class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
            @foreach ($organizations as $organisation)
                <option value="{{ route('organization.register', ['organization' => $organisation->slug]) }}"
                        data-marqueur="organisation-{{ $organisation->slug }}"
                        @selected($courante && $courante->is($organisation))>
                    {{ $organisation->name }}
                </option>
            @endforeach
        </select>

        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
            {{ __('auth.organization_choice_hint') }}
        </p>
    </div>

    <script>
        (function () {
            var menu = document.getElementById('organisation-inscription');
            if (!menu) { return; }

            // L'adresse d'OU l'on vient, pour ne pas recharger la meme page
            // quand on revient sur l'option deja selectionnee.
            var depart = menu.value;

            menu.addEventListener('change', function () {
                if (menu.value && menu.value !== depart) {
                    window.location.href = menu.value;
                }
            });
        })();
    </script>
@endif
