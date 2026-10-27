{{--
    TASK-1714 volet 1 — choisir son Organization depuis le point d'entree
    generique.

    ## Pourquoi un `<dialog>` natif et pas `<x-modal>`

    Mesure, pas preference : sur le layout INVITE, `window.Alpine` vaut
    `undefined`. Alpine n'est pas importe par `resources/js/app.js` ; il arrive
    avec `@livewireScripts`, declare uniquement dans `layouts/admin` et
    `layouts/org-admin`. Un `<x-modal>` pose ici rend donc son balisage — il est
    bien dans le DOM — mais reste **inerte** : `x-on:open-modal.window` n'est
    jamais ecoute et le conteneur garde `display: none`.

    C'est exactement le piege « asserter le balisage ne protege pas la garde qui
    le conditionne » (T1667) : une garde qui aurait cherche la liste dans le
    HTML serait passee au VERT sur une modale que personne ne peut ouvrir. Le
    defaut a ete trouve au navigateur, pas aux tests.

    `<dialog>` + `showModal()` donne nativement le piege a focus, la touche
    Echap et l'arriere-plan — sans aucune dependance a charger sur une page
    publique.

    `data-marqueur` n'est pas cosmetique : les gardes mesurent CETTE region, et
    non la page (lecon T1697).
--}}
@props(['organizations', 'courante' => null])

@if ($organizations->isNotEmpty())
    <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-700 text-center" data-marqueur="selecteur-organisation">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('auth.organization_choice_intro') }}
            <button type="button"
                    data-marqueur="ouvrir-choix-organisation"
                    class="font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 underline underline-offset-2 transition">
                {{ __('auth.organization_choice_link') }}
            </button>
        </p>
    </div>

    <dialog data-marqueur="dialogue-organisations"
            class="w-full max-w-lg rounded-2xl p-0 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 shadow-xl backdrop:bg-gray-900/60">
        <div class="p-6" data-marqueur="liste-organisations">
            <h2 class="text-lg font-semibold">{{ __('auth.organization_choice_title') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('auth.organization_choice_subtitle') }}
            </p>

            <ul class="mt-4 space-y-2 max-h-80 overflow-y-auto">
                @foreach ($organizations as $organisation)
                    @php $estCourante = $courante && $courante->is($organisation); @endphp
                    <li>
                        {{-- Le lien pointe la route d'INSCRIPTION de l'Organization,
                             pas sa page d'accueil : la personne a deja dit ce
                             qu'elle venait faire. --}}
                        <a href="{{ route('organization.register', ['organization' => $organisation->slug]) }}"
                           data-marqueur="organisation-{{ $organisation->slug }}"
                           @class([
                               'flex items-center justify-between gap-3 rounded-lg border px-4 py-3 transition',
                               'border-indigo-400 bg-indigo-50 dark:bg-indigo-900/20 dark:border-indigo-500' => $estCourante,
                               'border-gray-200 dark:border-gray-700 hover:border-indigo-400 hover:bg-gray-50 dark:hover:bg-gray-700/40' => ! $estCourante,
                           ])>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium truncate">{{ $organisation->name }}</span>
                                @if ($organisation->platform_tagline)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400 truncate">{{ $organisation->platform_tagline }}</span>
                                @endif
                            </span>
                            @if ($estCourante)
                                <span class="flex-shrink-0 text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                                    {{ __('auth.organization_choice_current') }}
                                </span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-5 flex justify-end">
                <button type="button" data-marqueur="fermer-choix-organisation"
                        class="px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition">
                    {{ __('auth.organization_choice_close') }}
                </button>
            </div>
        </div>
    </dialog>

    <script>
        (function () {
            var racine = document.currentScript.previousElementSibling;           // <dialog>
            var zone = document.querySelector('[data-marqueur="selecteur-organisation"]');
            var ouvrir = zone && zone.querySelector('[data-marqueur="ouvrir-choix-organisation"]');
            var fermer = racine && racine.querySelector('[data-marqueur="fermer-choix-organisation"]');
            if (!racine || !ouvrir) { return; }

            ouvrir.addEventListener('click', function () { racine.showModal(); });
            if (fermer) { fermer.addEventListener('click', function () { racine.close(); }); }

            // Clic sur l'arriere-plan : `<dialog>` ne le ferme pas tout seul.
            // La cible du clic EST le dialogue lui-meme quand il tombe hors du
            // contenu — c'est le test le plus simple et il n'exige aucune
            // mesure de coordonnees.
            racine.addEventListener('click', function (e) { if (e.target === racine) { racine.close(); } });
        })();
    </script>
@endif
