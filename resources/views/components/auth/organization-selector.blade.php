{{--
    TASK-1714 volet 1 — choisir son organisation a l'inscription.

    ## A quoi ce menu sert, et c'est ce qui fixe tout le reste

    Cyril, 27/10 : « les personnes ne connaissent pas forcement l'adresse pour
    s'inscrire dans leur organisation, et c'est pour ca qu'on donne cette
    possibilite de passer par celle de BouclePro par defaut ».

    C'est un RATTRAPAGE, pas un selecteur universel. Trois consequences
    directes :

    - il n'apparait que sur l'organisation PRINCIPALE — le point de chute de
      qui ne sait pas ou aller. Quelqu'un qui arrive sur
      `/org/launchpals/register` connait son adresse ;
    - il ne NAVIGUE pas : il designe. Rafraichir la page ferait perdre ce qui
      est deja saisi, pour un resultat identique ;
    - rien n'est pre-selectionne : la personne designe son organisation, elle
      n'herite pas de celle ou le lien l'a menee.

    ## Ce que la frontiere de securite devient, et pourquoi elle tient

    Le champ determine desormais l'organisation ou le compte nait. Les valeurs
    acceptees cote serveur sont EXACTEMENT les organisations ouvertes a
    l'inscription — `is_active`, `is_public`, pas une sandbox. Ce sont celles
    dont la page d'inscription est de toute facon publiquement joignable :
    poster leur slug n'ouvre rien que naviguer n'ouvrirait. Une organisation
    privee, inactive ou sandbox n'est pas dans la liste, et son slug est refuse.

    Et la regle n'existe que la ou le menu est rendu : ailleurs le champ est
    ignore, sans quoi il suffirait de le poster a la main depuis n'importe
    quelle page.

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
                name="organisation_confirmee"
                required
                data-marqueur="menu-organisations"
                class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
            <option value="" data-marqueur="organisation-aucune" @selected(old('organisation_confirmee') === null)>
                {{ __('auth.organization_choice_placeholder') }}
            </option>
            @foreach ($organizations as $organisation)
                <option value="{{ $organisation->slug }}"
                        data-marqueur="organisation-{{ $organisation->slug }}"
                        @selected(old('organisation_confirmee') === $organisation->slug)>
                    {{ $organisation->name }}
                </option>
            @endforeach
        </select>

        <x-input-error :messages="$errors->get('organisation_confirmee')" class="mt-1.5" />

        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
            {{ __('auth.organization_choice_hint') }}
        </p>
    </div>
@endif
