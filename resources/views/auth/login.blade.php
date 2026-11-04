<x-guest-layout>
    @php
        /*
         * TASK-1742 — l'autorite est la ROUTE, jamais la variable d'habillage.
         *
         * `$currentOrganization` est partage par le `View::composer('*')` de
         * `AppServiceProvider` : faute d'Organization liee a la requete, il y
         * place l'organisation PAR DEFAUT pour habiller la page. Le lire comme
         * « nous sommes sur la porte d'une Organization » etait donc faux sur
         * `/login`, qui se presentait silencieusement comme la porte de Main :
         * pas de selecteur, le sous-titre de Main, et un lien « connexion
         * globale » qui invitait a aller la ou l'on etait deja.
         *
         * La route, elle, ne ment pas — et c'est deja elle qui fait autorite
         * cote serveur (`LoginRequest::organizationDeLaTentative()`).
         */
        $isOrg = request()->route('organization') !== null;
        $organisationDeLaPorte = $isOrg ? $currentOrganization : null;
        $ref = request()->input('ref');
        $loginAction = $isOrg ? route('organization.login', $currentOrganization) : route('login');
        $registerLink = $isOrg
            ? route('organization.register', array_filter(['organization' => $currentOrganization->slug, 'ref' => $ref]))
            : route('register', array_filter(['ref' => $ref]));
        $forgotPasswordLink = $isOrg ? route('organization.password.request', $currentOrganization) : route('password.request');

        /*
         * TASK-1749 (recette Cyril, 04/11) — les identifiants attendent que
         * l'Organization soit designee.
         *
         * Le menu de la porte d'Organization NAVIGUE : choisir recharge la
         * page. Quelqu'un qui saisit son adresse puis choisit son Organization
         * perd donc sa saisie et doit tout recommencer — et c'est le parcours
         * NORMAL de qui arrive par la marque la plus connue.
         *
         * Les deux champs sont donc verrouilles tant qu'aucun choix n'a ete
         * fait. C'est l'ordre naturel : d'abord ou, ensuite qui.
         *
         * `disabled` est rendu COTE SERVEUR, pas pose par un script : sans
         * JavaScript le verrou tient quand meme, et il se leve par la
         * navigation elle-meme.
         *
         * Il ne s'applique QUE sur la porte d'une Organization, et seulement
         * quand il y a plusieurs portes. Sur `/login`, le selecteur POSTE au
         * lieu de naviguer : rien n'y est perdu, et verrouiller y serait une
         * gene sans contrepartie.
         */
        $alternativesDeLaPorte = $isOrg ? ($organizationsAlternatives ?? collect()) : collect();
        $identifiantsVerrouilles = $isOrg
            && $alternativesDeLaPorte->isNotEmpty()
            && request()->query('org') !== $organisationDeLaPorte?->slug;
    @endphp

    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('auth.login_title') }}</h1>
        @if($isOrg)
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('auth.login_subtitle_org', ['name' => $organisationDeLaPorte?->name]) }}</p>
        @endif
    </div>

    <div class="px-8 py-6">
        <x-auth-session-status class="mb-5" :status="session('status')" />

        <form method="POST" action="{{ $loginAction }}">
            @csrf

            <div class="space-y-5">
                <!-- Email -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.email_label') }}
                    </label>
                    {{-- ?email= is a convenience when arriving from a Loop invitation link.
                         It is never a guarantee: the server re-checks the address against
                         the invitation after authentication. old() still wins on redisplay. --}}
                    <input id="email" type="email" name="email" value="{{ old('email', request('email')) }}"
                           required @if(! $identifiantsVerrouilles) autofocus @endif
                           autocomplete="username"
                           @disabled($identifiantsVerrouilles)
                           @if($identifiantsVerrouilles) data-login-locked @endif
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400 dark:disabled:bg-gray-800">
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />

                    {{-- TASK-1728 (A3) — la sortie de secours du parcours
                         d'Organization (arbitrage MASTER D1).

                         `/org/{slug}/login` n'authentifie plus que les comptes
                         DE ce tenant. Quelqu'un qui arrive ici par un favori,
                         alors que son compte vit ailleurs, recevrait sinon un
                         « identifiants incorrects » definitif sans savoir quoi
                         faire — et on ne peut pas lui dire ou est son compte
                         sans reveler son appartenance.

                         Ce lien est donc affiche a TOUT echec, sans condition
                         sur la cause : il ne distingue pas le mauvais mot de
                         passe du mauvais tenant, et ne constitue donc aucun
                         oracle. Il dit seulement qu'une autre porte existe. --}}
                    @if($isOrg && $errors->has('email'))
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400" data-login-global-fallback>
                        <a href="{{ route('login') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                            {{ __('auth.login_try_global') }}
                        </a>
                    </p>
                    @endif
                </div>

                {{-- TASK-1742 — changer d'Organization depuis SA porte.

                     MASTER veut pouvoir choisir l'Organization « sur chaque
                     page de connexion ». Sur `/org/{slug}/login`, un champ
                     POSTE contredirait la route — et le serveur le refuse
                     (T1728). On NAVIGUE donc vers l'autre porte.

                     La difference n'est pas cosmetique : une navigation change
                     la ROUTE, donc l'autorite reste exactement la ou T1728 l'a
                     mise. Rien n'est poste, rien n'est devine.

                     La liste ne contient que du PUBLIC et de l'ACTIF, et
                     jamais la porte courante : on y est deja. Elle ne dit rien
                     d'aucune appartenance — ce sont les memes Organizations
                     que `/login` propose a quiconque. --}}
                {{-- TASK-1749 (recette Cyril, 04/11) — les pastilles deviennent
                     un SELECTEUR, comme sur la porte globale.

                     ## Le defaut mesure, et ce n'etait PAS la couleur

                     Le bloc vivait deja ici, entre Email et Mot de passe. Mais
                     il se presentait en « Se connecter a une autre organisation : »
                     suivi de deux PASTILLES cliquables portant la meme bordure
                     arrondie que les champs voisins. Au milieu d'un formulaire,
                     deux boutons nommes « OFSH » et « LaunchPals » se lisent
                     comme des boutons d'envoi : saisir ses identifiants puis
                     cliquer sur l'un d'eux etait la lecture naturelle de ce
                     qu'on montrait.

                     Foncer le libelle aurait rendu la chose plus visible, pas
                     moins trompeuse. Un `<select>` etiquete, lui, ne ressemble
                     a aucun bouton d'envoi : c'est un champ, et il annonce
                     qu'il contient des choix.

                     ## Il NAVIGUE, il ne poste pas — et ce n'est pas un detail

                     Sur `/org/{slug}/login`, `LoginRequest::organizationDeLaTentative()`
                     rend l'Organization DE LA ROUTE et sort avant meme de lire
                     l'entree postee. Un `<select name="organization">` ici
                     serait un mensonge d'interface : il afficherait un choix
                     que le serveur ne regarde jamais.

                     Il porte donc `data-login-organization-nav` et AUCUN `name` :
                     rien ne part dans le POST, la route reste souveraine, et
                     l'autorite demeure exactement la ou T1728 l'a mise.

                     ## AUCUNE Organization n'est preselectionnee — demande Cyril

                     La premiere version affichait la porte courante (« BouclePro »)
                     comme option selectionnee. C'etait factuellement vrai et
                     pratiquement nuisible, pour une raison de PARCOURS D'ARRIVEE :

                     qui cherche OFSH tape la marque qu'il connait — « bouclepro »
                     dans un moteur, `bouclepro.com` dans la barre d'adresse — et
                     atterrit donc ici, sur la porte de BouclePro. Un menu
                     PRE-REMPLI lui dit alors « rien a faire ici » : un champ deja
                     renseigne ne se relit pas. Il saisit ses identifiants, echoue,
                     et ne comprend pas pourquoi.

                     Le menu s'ouvre donc sur un libelle NEUTRE a valeur vide, et
                     liste TOUTES les Organizations publiques — la courante
                     comprise, sans quoi qui est au bon endroit ne pourrait pas se
                     le confirmer. Choisir conduit a la porte choisie ; ne rien
                     choisir ne conduit nulle part et ne rassure personne.

                     ## Sans JavaScript

                     Le menu ne bascule pas : on reste sur la porte ou l'on est,
                     qui est celle de l'URL — rien n'est casse. Les liens sont
                     neanmoins rendus dans un `<noscript>` pour que la sortie
                     existe toujours. --}}
                @if($isOrg && ($organizationsAlternatives ?? collect())->isNotEmpty())
                @php
                    // TOUTES les portes publiques, la courante comprise, triees
                    // par nom : le menu ne doit designer personne d'office.
                    $portes = $organizationsAlternatives
                        ->concat($organisationDeLaPorte ? [$organisationDeLaPorte] : [])
                        ->sortBy('name')
                        ->values();

                    /*
                     * TASK-1749, recette Cyril — la BOUCLE, et sa sortie.
                     *
                     * Defaut signale : sur `/org/main/login`, choisir
                     * « BouclePro » conduisait a `/org/main/login`, qui se
                     * rechargeait sur le libelle NEUTRE. Le choix etait perdu a
                     * chaque fois, et on ne pouvait plus se connecter.
                     *
                     * Le libelle neutre reste juste A L'ARRIVEE : personne n'a
                     * encore choisi, et preselectionner la porte dirait « rien a
                     * faire ici » a qui s'est trompe d'adresse. Mais apres un
                     * choix EXPLICITE, l'afficher n'est plus un defaut — c'est
                     * la confirmation du geste qu'on vient de faire.
                     *
                     * Les deux situations se distinguent par ce drapeau, pose
                     * par le menu au moment de naviguer. Il ne decide RIEN : la
                     * route reste souveraine, et on ne l'honore que s'il DESIGNE
                     * la porte ou l'on se trouve. Un parametre bricole a la main
                     * ne peut donc pas faire afficher une autre Organization que
                     * celle de l'URL.
                     */
                    $porteChoisie = request()->query('org') === $organisationDeLaPorte?->slug
                        ? $organisationDeLaPorte->slug
                        : null;
                @endphp
                <div data-login-organization-switch>
                    <label for="organization-nav" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.login_organization_nav_label') }}
                    </label>
                    <select id="organization-nav" data-login-organization-nav
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                        <option value="" @selected($porteChoisie === null)>{{ __('auth.login_organization_nav_placeholder') }}</option>
                        @foreach($portes as $porte)
                        <option value="{{ route('organization.login', ['organization' => $porte, 'org' => $porte->slug]) }}"
                                data-login-organization-switch-item="{{ $porte->slug }}"
                                @selected($porteChoisie === $porte->slug)>
                            {{ $porte->name }}
                        </option>
                        @endforeach
                    </select>
                    {{-- Un champ grise sans explication se lit comme une panne.
                         Tant que le verrou tient, l'aide dit ce qu'on attend ;
                         une fois l'Organization choisie, elle redevient la
                         phrase ordinaire. --}}
                    <p class="mt-1.5 text-xs {{ $identifiantsVerrouilles ? 'font-medium text-indigo-600 dark:text-indigo-400' : 'text-gray-500 dark:text-gray-400' }}"
                       data-login-organization-nav-hint>
                        {{ $identifiantsVerrouilles
                            ? __('auth.login_organization_nav_first')
                            : __('auth.login_organization_nav_hint') }}
                    </p>
                    <noscript>
                        {{-- Les ALTERNATIVES seulement, et la difference avec le
                             menu ci-dessus est VOULUE.

                             Le menu nomme toutes les portes, la courante comprise :
                             c'est ce qui permet a qui est au bon endroit de se le
                             confirmer, puisque rien n'est preselectionne.

                             Ici ce sont des LIENS. Un lien vers la page ou l'on se
                             trouve deja ne conduit nulle part — et la garde de
                             TASK-1742 l'interdit explicitement (« pas de lien vers
                             sa propre porte : on y est »). --}}
                        <ul class="mt-1.5 flex flex-wrap gap-2">
                            @foreach($organizationsAlternatives as $alternative)
                            <li>
                                <a href="{{ route('organization.login', ['organization' => $alternative, 'org' => $alternative->slug]) }}"
                                   class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 dark:border-gray-600 px-3 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $alternative->name }}
                                </a>
                            </li>
                            @endforeach
                        </ul>
                    </noscript>
                </div>

                {{-- Pose dans `head` : `layouts/guest` ne declare QU'UNE pile,
                     `@stack('head')`. Un `@push('scripts')` n'y serait empile
                     nulle part et sortirait sans aucune trace (mesure du 04/11).
                     L'ecouteur est DELEGUE sur `document` : il n'a besoin
                     d'aucun element present au moment ou il s'attache. --}}
                @push('head')
                <script>
                    document.addEventListener('change', function (e) {
                        var champ = e.target;
                        if (! champ || ! champ.hasAttribute('data-login-organization-nav')) { return; }
                        if (champ.value) { window.location.href = champ.value; }
                    });
                </script>
                @endpush
                @endif

                {{-- TASK-1728 (A3) — le selecteur d'Organization, FACULTATIF.

                     ## Ce qu'il est, et ce qu'il n'est pas

                     Il ne liste que des Organizations PUBLIQUES et ACTIVES. La
                     liste est donc la meme pour tout visiteur et ne dit RIEN
                     de personne : aucune appartenance individuelle n'y
                     apparait. C'est la condition qui rend acceptable un tel
                     champ sur une page ouverte a qui n'est pas authentifie —
                     proposer les Organizations de la personne aurait fait de
                     ce formulaire un oracle d'appartenance.

                     ## Pourquoi « facultatif » et pas « requis »

                     Tant que `users.email` est globalement unique, une adresse
                     ne designe qu'un compte : la selection n'a rien a
                     departager et le parcours historique continue sans elle.
                     La rendre obligatoire aurait casse toutes les connexions
                     existantes pour un besoin qui n'existe pas encore.

                     ## Les Organizations privees

                     Absentes de cette liste, par construction. Leur equivalent
                     est leur propre porte — `/org/{slug}/login` —, et elle n'a
                     pas besoin d'etre annoncee ici pour fonctionner.

                     Rien sur `/org/{slug}/login` : l'Organization y vient de la
                     ROUTE, et le serveur refuse qu'un champ poste la
                     contredise. --}}
                @if(! $isOrg && ($organizationsProposables ?? collect())->isNotEmpty())
                <div>
                    <label for="organization" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ __('auth.login_organization_label') }}
                    </label>
                    <select id="organization" name="organization" required
                            data-login-organization-selector
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm">
                        {{-- TASK-1749 — « — Toutes les organisations — » etait une
                             valeur RETENABLE qui elargissait la recherche a tous
                             les locataires. Elle devient un libelle d'invite :
                             `disabled` pour qu'on ne puisse pas y revenir apres
                             avoir choisi, `selected` pour qu'aucune Organization
                             ne soit designee d'office. --}}
                        <option value="" disabled {{ old('organization') ? '' : 'selected' }}>
                            {{ __('auth.login_organization_nav_placeholder') }}
                        </option>
                        @foreach($organizationsProposables as $proposable)
                        <option value="{{ $proposable->slug }}" {{ old('organization') === $proposable->slug ? 'selected' : '' }}>
                            {{ $proposable->name }}
                        </option>
                        @endforeach

                    </select>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('auth.login_organization_hint') }}
                    </p>
                    <x-input-error :messages="$errors->get('organization')" class="mt-1.5" />
                </div>
                @endif

                <!-- Mot de passe -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('auth.password_label') }}
                        </label>
                        @if (Route::has($isOrg ? 'organization.password.request' : 'password.request'))
                        <a href="{{ $forgotPasswordLink }}"
                           class="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                            {{ __('auth.forgot_password_link') }}
                        </a>
                        @endif
                    </div>
                    <input id="password" type="password" name="password"
                           required autocomplete="current-password"
                           @disabled($identifiantsVerrouilles)
                           @if($identifiantsVerrouilles) data-login-locked @endif
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition text-sm disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400 dark:disabled:bg-gray-800">
                    <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                </div>

                <!-- Se souvenir de moi -->
                <div>
                    <label for="remember_me" class="inline-flex items-center gap-2 cursor-pointer">
                        <input id="remember_me" type="checkbox" name="remember"
                               class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:bg-gray-700">
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('auth.remember_me') }}</span>
                    </label>
                </div>

                <!-- Bouton connexion -->
                {{-- Verrouille lui aussi : un bouton actif au-dessus de champs
                     grises promettrait une action impossible. --}}
                <button type="submit"
                        @disabled($identifiantsVerrouilles)
                        class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500 dark:disabled:bg-gray-700">
                    {{ __('auth.login_button') }}
                </button>
            </div>
        </form>
    </div>

    <!-- Lien inscription -->
    <div class="px-8 py-5 bg-gray-50 dark:bg-gray-700/40 border-t border-gray-100 dark:border-gray-700 text-center">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('auth.no_account') }}
            <a href="{{ $registerLink }}" class="font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 transition">
                {{ __('auth.register_link') }}
            </a>
        </p>
    </div>
</x-guest-layout>
