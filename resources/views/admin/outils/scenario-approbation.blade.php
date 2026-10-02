{{-- TASK-1650 — l'etape HUMAINE (CDC 12.2).

     « VALID techniquement ne signifie pas encore autorise a Load. Le SuperAdmin
     doit confirmer le contenu EXACT. »

     Cet ecran montre donc ce qu'il y a DANS le monde — compteurs, digest, zero
     erreur — et pas seulement le nom du scenario. On approuve un contenu, pas
     un titre : c'est la raison d'etre de l'etape, et un ecran qui se
     contenterait d'un bouton « Approuver » la viderait de son sens.

     Le Load vit ici aussi, parce qu'il n'a de sens qu'apres l'approbation, et
     que separer les deux ferait chercher le bouton ailleurs. --}}
<x-admin-layout :title="$version->name">
    <div class="mb-4">
        <a href="{{ route('admin.outils.scenarios.show', $version) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ __('admin.scenario_manager.editor_back') }}</a>
    </div>

    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.scenario_manager.approval_title') }}</h1>
    <p class="mt-1 mb-6 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.approval_intro') }}</p>

    @if(session('status'))
        <div class="mb-6 rounded-xl border border-green-300 bg-green-50 p-4 text-sm text-green-900 dark:border-green-800 dark:bg-green-900/20 dark:text-green-200">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <ul class="space-y-1 text-sm text-red-900 dark:text-red-200">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="mb-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $version->name }} {{ $version->version }}</h2>

        <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.approval_counters') }}</h3>

        @if($compteurs === [])
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_no_counters') }}</p>
        @else
            <ul class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm text-gray-700 sm:grid-cols-3 dark:text-gray-300">
                @foreach($compteurs as $nom => $valeur)
                    @continue(! is_scalar($valeur))
                    <li data-counter="{{ $nom }}"><span class="font-semibold text-gray-900 dark:text-gray-100">{{ $valeur }}</span> {{ $nom }}</li>
                @endforeach
            </ul>
        @endif

        {{-- « 0 erreur » est une PREUVE dans l'exemple du CDC 12.2. Elle ne
             vaut donc que si une validation a eu lieu : l'afficher en vert sur
             un document jamais valide contredirait, sur la meme carte, la
             phrase « ce scenario n'a pas encore ete valide ». --}}
        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('admin.scenario_manager.preview_verdict') }} :
            <span data-verdict="{{ is_scalar($verdict) ? $verdict : 'none' }}" class="font-mono font-semibold text-gray-900 dark:text-gray-100">
                {{ is_scalar($verdict) ? $verdict : __('admin.scenario_manager.preview_verdict_none') }}
            </span>
        </p>

        @if($verdict !== null)
            <p class="mt-1 text-sm {{ $erreurs === [] ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                {{ $erreurs === []
                    ? __('admin.scenario_manager.approval_no_error')
                    : __('admin.scenario_manager.approval_errors', ['nombre' => count($erreurs)]) }}
            </p>
        @endif

        <div class="mt-4">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_digest') }}</p>
            <p class="break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ $version->digest ?: __('admin.scenario_manager.no_digest') }}</p>
        </div>
    </section>

    @if(! $version->isValid())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
            {{ __('admin.scenario_manager.approval_blocked') }}
        </div>
    @elseif($version->isLoaded())
        {{-- Deja charge : cet ecran n'a plus rien a proposer, et annoncer
             « une NOUVELLE sandbox va etre creee » y serait faux. On dit ce
             qui EST, et on renvoie vers le monde. --}}
        <div class="rounded-xl border border-indigo-300 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
            <p class="text-sm text-indigo-900 dark:text-indigo-200">
                {{ __('admin.scenario_manager.flash_already_loaded', ['slug' => $version->scenarioPackLoad?->organization?->slug ?? '—']) }}
            </p>
            <a href="{{ route('admin.outils.scenarios.show', $version) }}"
               class="mt-3 inline-flex rounded-lg border border-indigo-400 px-3 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100 dark:border-indigo-600 dark:text-indigo-300 dark:hover:bg-indigo-900/40">
                {{ __('admin.scenario_manager.sandbox_title') }}
            </a>
        </div>
    @else
        <div class="flex flex-wrap items-start gap-3">
            @unless($dejaApprouve)
                <form method="POST" action="{{ route('admin.outils.scenarios.approve', $version) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ __('admin.scenario_manager.approval_confirm') }}
                    </button>
                </form>
            @else
                <p class="rounded-lg bg-green-50 px-4 py-2 text-sm text-green-900 dark:bg-green-900/20 dark:text-green-200">
                    {{ __('admin.scenario_manager.approval_already') }}
                </p>

                @unless($version->isLoaded())
                    <form method="POST" action="{{ route('admin.outils.scenarios.load', $version) }}">
                        @csrf
                        {{-- TASK-1657 — le CTA sur le primaire CANONIQUE.

                             Il portait `bg-green-700` / `hover:bg-green-800`.
                             Aucune des deux n'est generee dans le CSS servi : la
                             page d'approbation est le SEUL endroit du depot a
                             employer `bg-green-700` en classe NUE — les sept
                             autres vues ne l'utilisent qu'en `hover:`, variante
                             qui, elle, existe. Le bouton rendait donc
                             `text-white` SANS AUCUN FOND, blanc sur une carte
                             claire : invisible.

                             Ce n'etait pas un defaut de contraste mais de
                             GENERATION. Tailwind ne produit que ce qu'il scanne,
                             et une classe employee a un seul endroit disparait
                             de tout actif construit avant l'ecran qui l'emploie.

                             Le correctif n'est donc pas de rebatir l'actif — il
                             masquerait le probleme jusqu'au prochain ecran — mais
                             d'utiliser les classes du primaire canonique, celles
                             que le bouton « approuver » de CETTE MEME page porte
                             deja, et que 212 vues partagent. Une classe utilisee
                             partout ne peut pas manquer d'un build.

                             Un anneau de focus est ajoute : le §9 exige que le
                             CTA reste identifiable au clavier, et rien ne le
                             rendait visible au focus.

                             Il emploie `focus:ring-*`, celui des composants du
                             depot, et NON `focus-visible:outline-*` : cette
                             seconde famille n'est pas generee non plus — je l'y
                             avais d'abord mise, reintroduisant le defaut meme
                             que ce bloc corrige. Chaque classe posee ici a ete
                             verifiee presente dans le CSS servi. --}}
                        <button type="submit"
                                data-cta="load"
                                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            {{ __('admin.scenario_manager.load_action') }}
                        </button>
                    </form>
                @endunless
            @endunless
        </div>

        {{-- CDC 13.2, dit EXPLICITEMENT et avant le clic : le Manager ne
             propose jamais « injecter dans l'Organization X ». --}}
        <p class="mt-4 rounded-lg bg-blue-50 p-3 text-xs text-blue-900 dark:bg-blue-900/20 dark:text-blue-200">
            {{ __('admin.scenario_manager.load_notice') }}
        </p>
    @endif
</x-admin-layout>
