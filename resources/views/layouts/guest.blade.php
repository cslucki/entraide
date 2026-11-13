@php
    /*
     * TASK-1749 (demande Cyril, 04/11) — la porte de chaque Organization porte
     * SA couleur de fond, et toujours en mode clair.
     *
     * ## Pourquoi, et ce que cela achete
     *
     * Les trois portes de connexion etaient grises a l'identique. Rien ne
     * signalait qu'on avait change d'Organization — c'est le meme defaut que le
     * menu de cette TASK, vu sous un autre angle : qui arrive par la marque la
     * plus connue n'a aucun signal qu'il n'est pas au bon endroit. La couleur
     * de fond en est un, immediat et sans texte a lire.
     *
     * ## L'autorite est la ROUTE, jamais `currentOrganization()`
     *
     * Meme piege que T1742, T1725 et le correctif 1 de cette TASK : prive
     * d'Organization liee a la requete, le `View::composer('*')` d'
     * `AppServiceProvider` place l'Organization PAR DEFAUT pour habiller la
     * page. La lire ici peindrait `/login` — qui n'est la porte de PERSONNE —
     * aux couleurs de BouclePro, soit exactement le signal trompeur qu'on
     * cherche a supprimer.
     *
     * Sans prefixe d'Organization dans l'URL, c'est donc le theme par defaut de
     * la plateforme qui sert, et c'est voulu.
     *
     * ## La source est `bp_themes()`, pas la table
     *
     * `Theme` et `bp_themes()` disent la meme chose, mais `bp_themes()` est
     * celle que `theme-tokens` et les deux layouts applicatifs lisent deja.
     * Aller chercher la table ici aurait cree une SECONDE autorite de theme,
     * qui aurait fini par diverger. `tokens` est la palette CLAIRE ; `page` est
     * le jeton nomme « Fond page » dans `/admin/themes`.
     *
     * Le repli est le theme par defaut : une cle de theme absente du cache ne
     * doit pas rendre un fond vide. Mesure du 04/11 — le cache local avait
     * deux themes de retard sur la table (`lp`, `ut` manquants), et LaunchPals
     * serait tombe sur la couleur de `zen`. `Theme::regenerateCache()` l'a
     * remis a jour ; le repli reste la garde.
     *
     * ## Le fond vient de la VITRINE, le jeton n'est plus qu'un repli
     *
     * Demande Cyril, 04/11 : « que les pages de login héritent du même fond que
     * leur homepage respective ». Le jeton « Fond page » etait un aplat ; les
     * trois vitrines portent en realite des DEGRADES, dont celui de LaunchPals
     * qui est vif et impose du texte blanc. Un aplat ne pouvait pas en rendre
     * compte.
     *
     * `bp-door-backgrounds.css` reprend donc les trois fonds, indexes sur
     * `homepage_template` — la colonne qui decide reellement de la vitrine dans
     * `OrganizationLandingController`, et non le theme : deux Organizations
     * peuvent partager un theme et servir deux vitrines.
     *
     * L'aplat de theme reste pose en `background-color` SOUS la feuille : si un
     * gabarit de vitrine n'a pas encore son bloc, la porte garde une couleur
     * d'Organization plutot qu'un fond blanc.
     */
    $bpThemesGuest = bp_themes();
    $bpOrgDeLaPorte = request()->route('organization')
        ? \App\Models\Organization::findBySlug((string) request()->route('organization'))
        : null;

    $bpCleThemeGuest = (string) ($bpOrgDeLaPorte
        ? bp_organization_theme_key($bpOrgDeLaPorte)
        : $bpThemesGuest['default']);

    $bpFondPage = $bpThemesGuest['themes'][$bpCleThemeGuest]['tokens']['page']
        ?? ($bpThemesGuest['themes'][$bpThemesGuest['default']]['tokens']['page'] ?? '#F9FAFB');

    // Sans Organization dans l'URL, `/login` n'est la porte de personne : elle
    // prend la vitrine par defaut de la plateforme, pas celle d'une Organization
    // qu'on aurait devinee.
    $bpPorte = (string) ($bpOrgDeLaPorte?->homepage_template ?: 'bouclepro_hero_v2');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bp-theme="{{ $bpCleThemeGuest }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        {{-- TASK-1737 : le contrat PWA est declare plus bas, avec les favicons. --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Entraide') }}</title>

        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="shortcut icon" href="/favicon.ico">

        {{-- TASK-1737 — le contrat d'installabilite, declare une seule fois. --}}
        <x-pwa-head />
        <x-pwa-boot />

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        {{-- TASK-1749 — les portes de connexion sont TOUJOURS en mode clair.

             Le script qui vivait ici posait `.dark` d'apres `localStorage` ou
             le mode global. Sur ces pages il travaillait CONTRE la couleur
             d'Organization : un visiteur dont le navigateur gardait « sombre »
             voyait les trois portes identiques et noires, et le signal de
             changement d'Organization disparaissait.

             La classe est donc RETIREE explicitement, et pas seulement « non
             ajoutee » : une preference deja posee par une page applicative
             doit etre defaite, pas ignoree. --}}
        <script>
            document.documentElement.classList.remove('dark');
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- TASK-1749 — APRES `@vite` : la feuille des portes surcharge le fond
             que Tailwind pose sur `body`. Avant, elle aurait ete perdue. --}}
        <link rel="stylesheet" href="{{ asset('css/bp-door-backgrounds.css') }}?v={{ filemtime(public_path('css/bp-door-backgrounds.css')) }}">
        @stack('head')

        <style>
            .mobile-safe-top { padding-top: 0; }
            .mobile-safe-bottom-auth { padding-bottom: 0; }
            @media (max-width: 767px) {
                .mobile-safe-top { padding-top: calc(3.5rem + env(safe-area-inset-top, 0px)); }
                .mobile-safe-bottom-auth { padding-bottom: calc(4rem + env(safe-area-inset-bottom, 0px)); }
            }
        </style>
    </head>
    {{-- `style` et non une classe : la couleur vient de la base de themes, elle
         ne peut pas etre connue de Tailwind a la compilation. Elle est posee en
         `background-color`, donc SOUS le `background-image` de la feuille des
         portes — les deux cohabitent au lieu de se battre.

         `dark:bg-gray-900` est retire plutot que laisse inerte : le mode sombre
         est desormais impossible ici, et une classe qui ne peut plus s'appliquer
         est un mensonge pour qui lira ce fichier ensuite. --}}
    <body class="font-sans antialiased"
          data-bp-door="{{ $bpPorte }}"
          data-bp-page-bg="{{ $bpFondPage }}"
          style="background-color: {{ $bpFondPage }}">
        <x-mobile-topbar title="Connexion" :brand-name="$brandOrganizationName ?? config('app.name')" />
        <x-mobile-bottom-nav />

        <div class="min-h-screen flex flex-col mobile-safe-top mobile-safe-bottom-auth">

            <!-- Header avec logo -->
            <div class="hidden md:flex justify-center pt-10 pb-6">
                {{-- TASK-1605 — l'Organization PAR DEFAUT est bornee elle aussi
     (arbitrage MASTER : « main -> /org/main »). L'ancienne regle l'excluait et
     la renvoyait sur la racine. Le declencheur est desormais l'URL. --}}
                @php
                    // TASK-1697 — le NOM et le LIEN designaient deux
                    // Organizations differentes.
                    //
                    // Signale par Cyril sur `/verify-email` : l'en-tete
                    // affichait « Organismes de formation solidaires et
                    // humanistes » et menait a `/`. Mesure :
                    // `AppServiceProvider` prend `brandOrganizationName` sur
                    // l'Organization de la PERSONNE CONNECTEE quand aucune
                    // n'est courante, tandis que `organizationScopedUrl()`
                    // exige un prefixe `/org/...` dans l'URL — par doctrine
                    // assumee, « le declencheur est l'URL » (T1605).
                    //
                    // On accorde donc les deux ICI, et pas dans le helper
                    // partage : huit autres vues en dependent, dont des pages
                    // PUBLIQUES (vitrines, mycelium, mentions legales) ou
                    // basculer sur l'Organization du visiteur serait faux.
                    //
                    // Un visiteur anonyme ne change pas de comportement.
                    $slugEntete = request()->route('organization')
                        ?: (auth()->check() ? auth()->user()?->organization?->slug : null);

                    $homeLink = $slugEntete && Route::has('organization.home')
                        ? route('organization.home', ['organization' => $slugEntete])
                        : organizationScopedUrl('home', 'organization.home');
                @endphp
                <a href="{{ $homeLink }}" data-guest-brand class="flex items-center gap-3 group">
                    <img src="{{ $brandLogoUrl }}" alt="" class="h-10 w-10" aria-hidden="true">
                    <div class="text-left">
                        <div class="text-xl font-bold text-gray-900 dark:text-white leading-tight group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition">
                            {{ $brandOrganizationName ?? config('app.name') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 leading-tight">
                            {{ $platformTagline ?? 'Échangez vos talents' }}
                        </div>
                    </div>
                </a>
            </div>

            <!-- Carte du formulaire -->
            <div class="flex-1 flex flex-col items-center px-4 pb-12">
                <div class="w-full max-w-md bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden border border-gray-100 dark:border-gray-700">
                    {{ $slot }}
                </div>
            </div>

            @include('partials.footer')
        </div>

        {{-- TASK-1817 — Alpine manquait ICI, et nulle part ailleurs.
             =====================================================================
             Ce layout charge `app.js`, qui UTILISE `window.Alpine` (ses stores,
             `registerAlpineStores()`) mais ne l'IMPORTE jamais. Alpine arrive en
             realite par `livewire.min.js`, que Livewire auto-injecte sur toute
             page portant un de ses composants.

             Les pages invitees n'en portent aucun. Mesure du 13/11 en navigateur :

               /login, /register   window.Alpine = undefined, window.Livewire =
                                   undefined, mais 2 [x-data] et 1 [x-cloak] dans
                                   le DOM
               /dashboard, /membres  Alpine 3.15.11, Livewire charge, stores poses

             La panne etait SILENCIEUSE — zero erreur console, puisque Alpine ne
             tournait pas et ne pouvait donc pas echouer. Le symptome visible :
             le panneau du menu mobile porte `x-cloak`, restait `display:none`
             A JAMAIS, et cliquer le bouton ne faisait rien.

             La directive de scripts Livewire plutot qu'un demarrage autonome
             d'Alpine :
             - c'est deja le precedent du depot (`admin.blade.php`,
               `org-admin.blade.php` le posent au meme endroit) ;
             - aucun paquet supplementaire : Alpine est DANS le bundle Livewire,
               c'est exactement l'instance que les autres layouts utilisent ;
             - une seule instance, donc aucun double demarrage a craindre ;
             - `app.js` enregistre ses stores sur `alpine:init`, avec sa propre
               garde (`__boucleProAlpineStoresRegistered`) : ils se posent sans
               rien changer.

             Les autres layouts ne sont pas touches. --}}
        @livewireScripts
    </body>
</html>
