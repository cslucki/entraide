{{--
    TASK-1494 — la landing du mode SHELL FIRST.

    ## Ce fichier SUPERSEDE un contrat ecrit, il ne corrige pas un accident

    `organization/partials/guest-shell-overlay.blade.php` documente depuis
    TASK-1443 le comportement observe aujourd'hui :

    > « shell_first : le Shell est l'experience principale, inclus en HAUT
    >   (`position` = top), LE CONTENU PUBLIC CLASSIQUE RESTE ENTIER JUSTE EN
    >   DESSOUS. »

    Ce que Cyril a mesure — le grand Shell affiche, la landing marketing rendue
    derriere — etait donc l'intention de TASK-1443, pas un defaut d'execution.
    MASTER l'a arbitre autrement : en Shell First, le Shell EST l'experience,
    et rendre la landing complete derriere dedouble l'experience au lieu de la
    remplacer.

    ## Pourquoi une vue dediee plutot que trois conditionnelles

    Trois gabarits de landing existent (`home`, `hero-v2`, `artscilab-hero`),
    518 lignes de balisage marketing au total, chacun incluant le partial du
    Shell deux fois. Encadrer leur contenu de conditionnelles aurait touche les
    trois, avec un risque de regression sur les modes `overlay` et OFF qui,
    eux, ne changent pas.

    Le mode est donc decide UNE fois, dans le controleur, avant le choix du
    gabarit. Aucun balisage existant n'est modifie.

    ## Ce qui est reutilise, et ce qui ne l'est pas

    Reutilise tel quel : le partial du Shell (meme runtime, meme JSON, meme
    garde economique), le selecteur de langue de `hero-v2`, la route de
    connexion. Rien n'est reecrit.

    Volontairement ABSENT, parce que c'est la decision meme : hero marketing,
    cartes, statistiques, bloc Ateliers, pied de page complet.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bp-theme="{{ bp_organization_theme_key($organization) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $organization->name }}</title>
    <link rel="icon" href="{{ asset('brand/bouclepro-symbol-64.png') }}">
    {{-- TASK-1500 : le JS rejoint le CSS. Le rail partage est un composant
         Alpine (bascules de theme, menus) : `layouts/guest.blade.php` charge
         exactement ce couple pour les pages publiques. --}}
    @php
        // TASK-1500 : la MEME source que `layouts/app` (TASK-1471). Le cycleur de
        // theme du rail lit `window.bpThemes` ; sans lui il ne connaissait que
        // son repli {zen, sable} — deux themes sur six, mesure au navigateur.
        $bp = bp_themes();
    @endphp
    <script>
        // Copie fidele du bloc de `layouts/app` : memes cles, memes lectures.
        // Un visiteur qui s'inscrit garde ainsi le theme choisi ici — c'est le
        // meme `localStorage.bpTheme` et la meme `localStorage.theme` des deux
        // cotes de la porte.
        window.bpThemes = @json(collect($bp['themes'])->map(fn ($theme) => ['label' => $theme['label']])->all());
        window.bpDefaultTheme = @json($bp['default']);
        var orgThemeKey = @json(bp_organization_theme_key($organization)) || window.bpDefaultTheme;
        document.documentElement.dataset.bpTheme = localStorage.bpTheme || orgThemeKey;

        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && @json($globalColorMode ?? 'dark') === 'dark')) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- TASK-1500 : les variables `--bp-*`. Mesure au navigateur : sur ce
         document autonome elles etaient toutes ABSENTES — `--bp-surface`,
         `--bp-page`, `--bp-text` : UNDEFINED. La bascule sombre posait bien la
         classe `dark`, mais chaque couleur retombait sur son repli clair : rien
         ne changeait a l'ecran. Meme producteur que `layouts/app`, `org-admin`
         et les deux landings publiques. --}}
    <x-theme-tokens />
    <style>
        /* Autonome comme le partial lui-meme : le Shell First ne doit dependre
           d'aucun composant marketing pour s'afficher. */
        .bpsf-page { min-height: 100vh; min-height: 100dvh; height: 100dvh; overflow: hidden; display: flex; flex-direction: column; background: var(--bp-page, #f7f7f5); color: var(--bp-text, #16181d); }
        .bpsf-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .85rem 1rem; border-bottom: 1px solid var(--bp-border, #e6e6e1); }
        .bpsf-brand { display: flex; align-items: center; gap: .55rem; font-weight: 700; font-size: .95rem; letter-spacing: -.01em; text-decoration: none; color: inherit; }
        .bpsf-logo { display: block; width: 28px; height: 28px; border-radius: 6px; }
        .bpsf-org { font-weight: 500; color: var(--bp-muted, #6b7280); }
        .bpsf-right { display: flex; align-items: center; gap: .6rem; }
        .bpsf-lang button { border: 0; background: none; font: inherit; font-size: .75rem; padding: .15rem .35rem; color: var(--bp-muted, #6b7280); cursor: pointer; border-radius: .35rem; }
        .bpsf-lang button[aria-current="true"] { background: var(--bp-panel, #edece7); color: var(--bp-text, #16181d); font-weight: 600; }
        .bpsf-login { font-size: .8rem; font-weight: 600; text-decoration: none; color: var(--bp-text, #16181d); border: 1px solid var(--bp-border, #e6e6e1); border-radius: 999px; padding: .4rem .85rem; }
        /* TASK-1497 : la page utile appartient ENTIEREMENT au Shell. La
           gouttiere de 32 px que TASK-1496 laissait sur grand ecran faisait
           encore un cadre autour de l'application ; il n'y en a plus aucune,
           a aucune taille. Seule la barre du haut garde son confort de lecture. */
        .bpsf-main { flex: 1; display: flex; flex-direction: column; min-height: 0; padding: 0; }
        @media (min-width: 641px) {
            .bpsf-bar { padding: 1rem 2rem; }
        }
        /* TASK-1500 — le rail partage est `fixed w-20` et `hidden md:flex` :
           80 px a partir de 768 px, rien en dessous. Il ne pousse donc rien,
           c'est la page qui se decale — d'un seul geste pour la barre et la
           conversation, sans toucher au partial du Shell. */
        @media (min-width: 768px) {
            .bpsf-page--rail { padding-left: 80px; }
            /* Au-dessus de 768 px le rail porte deja la marque, la langue et le
               theme : les repeter dans la barre ferait deux fois la meme chose
               a 60 px d'ecart. En dessous, le rail n'existe pas et la barre
               redevient le seul endroit ou ils peuvent vivre. */
            .bpsf-page--rail .bpsf-brand,
            .bpsf-page--rail .bpsf-lang,
            .bpsf-page--rail .bpsf-theme { display: none; }
        }
        .bpsf-footer { display: none; }
        @media (min-width: 768px) {
            .bpsf-footer { display: block; flex-shrink: 0; border-top: 1px solid var(--bp-border, #e6e6e1); }
        }
        .bpsf-theme { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--bp-border, #e6e6e1); background: var(--bp-panel, #edece7); color: var(--bp-muted, #6b7280); cursor: pointer; }
        .bpsf-theme svg { width: 15px; height: 15px; }
        .bpsf-theme .bpsf-moon { display: none; }
        html.dark .bpsf-theme .bpsf-sun { display: none; }
        html.dark .bpsf-theme .bpsf-moon { display: block; }
        .bpsf-org-only { font-weight: 600; font-size: .95rem; letter-spacing: -.01em; }
    </style>
</head>
@php
    // TASK-1500 : le rail suit le mode CHOISI, jamais une preference locale.
    $gsRail = \App\Support\GuestShell\GuestShellDisplayMode::showsRail($guestShell['display']['mode'] ?? null);
@endphp
<body class="bpsf-page @if($gsRail) bpsf-page--rail @endif">
@if($gsRail)
    {{-- TASK-1500 — LE MEME composant que l'application, pas une copie.

         Une premiere version reproduisait le rail dans un partial dedie, avec
         les huit chemins d'icones recopies. Arbitrage Cyril : « il faut que ce
         soit les memes. Ainsi si on fait une modif sur le rail gauche, cela
         apparaitra sur le shell welcome ou sur l'application. » Un rail copie
         diverge au premier changement, et la copie ne previent jamais.

         Le composant se degrade deja seul pour un visiteur, verifie ligne a
         ligne : `$coopActions` vaut `[]` sans session — le bouton « Cooperer »
         disparait par sa propre garde ; notifications et avatar sont sous
         `@auth` ; les compteurs de non-lus sont derriere `auth()->check()`.
         Restent la langue, le theme et la navigation, qui sont justement ce
         qu'on veut offrir. --}}
    <x-app-side-nav />
@endif
    {{-- TASK-1509 — `x-data` n'est pas decoratif ici : Alpine n'initialise que
         les arbres qui partent d'une racine `x-data`, et cette page n'en avait
         AUCUNE. Le `@click="$store.darkMode.toggle()"` de la bascule n'etait
         donc jamais cable — sans la moindre erreur console, ce qui l'a rendu
         invisible. En mode « avec rail », le rail (`<aside x-data>`) portait sa
         propre bascule et masquait le probleme ; en mode SANS rail, celle-ci
         est la SEULE, et elle ne fonctionnait a aucune largeur. --}}
    <header class="bpsf-bar" x-data>
        {{-- Le logo, puis le nom. L'Organization par defaut S'APPELLE BouclePro :
             afficher « BouclePro · BouclePro » etait le rendu mesure au
             navigateur, le nom n'est donc repete que s'il apporte quelque
             chose. C'est la SEULE barre de la page : l'entete interne du Shell
             est masque en shell_first. --}}
        {{-- TASK-1500 : la marque reste TOUJOURS dans le DOM, et c'est le CSS qui
             la retire au-dessus de 768 px quand le rail la porte deja. Une
             premiere version la supprimait en Blade : le rail etant
             `hidden md:flex`, le telephone se retrouvait alors sans aucun logo.
             Cacher par media query, c'est cacher exactement la ou le rail
             apparait — la meme rupture, une seule fois ecrite. --}}
        <a class="bpsf-brand" href="{{ route('organization.home', $organization) }}">
            <img class="bpsf-logo" src="{{ asset('brand/bouclepro-symbol-64.png') }}" alt="" aria-hidden="true" width="28" height="28">
            <span>BouclePro</span>
            @if($organization->name !== 'BouclePro')<span class="bpsf-org">· {{ $organization->name }}</span>@endif
        </a>
        <div class="bpsf-right">
            <div class="bpsf-lang" aria-label="Langue / Language">
                @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label)
                    <form method="POST" action="{{ route('locale.switch', ['locale' => $code]) }}" style="display:inline">
                        @csrf
                        <button type="submit" @if(app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</button>
                    </form>
                @endforeach
            </div>

            {{-- La bascule clair/sombre, meme magasin que partout ailleurs
                 (`$store.darkMode` : classe `dark` sur <html> + cle `theme`).
                 Elle disparait au-dessus de 768 px, ou le rail porte la sienne. --}}
            <button type="button" class="bpsf-theme" @click="$store.darkMode.toggle()" aria-label="{{ __('navigation.toggle_display_mode') }}" title="{{ __('navigation.toggle_display_mode') }}">
                <svg class="bpsf-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 1012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                <svg class="bpsf-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </button>
            @guest
                <a class="bpsf-login" href="{{ route('organization.login', $organization) }}">{{ org_trans('navigation.login') }}</a>
            @else
                <a class="bpsf-login" href="{{ route('dashboard') }}">{{ __('navigation.dashboard') }}</a>
            @endguest
        </div>
    </header>

    <main class="bpsf-main">
        {{-- Le MEME partial, le meme runtime. `position` = top est ce que le mode shell_first monte. --}}
        @include('organization.partials.guest-shell-overlay', ['guestShell' => $guestShell ?? null, 'organization' => $organization, 'position' => 'top'])
    </main>

    {{-- TASK-1500 — le pied de page BouclePro, a partir de 768 px.

         Le MEME `partials/footer` que l'accueil public et `layouts/guest` : il
         porte deja gouvernance, mentions legales, kit demo, depot et numero de
         version. En ecrire un seul pour cette page aurait fige une copie de
         plus — meme raison que pour le rail.

         Sur telephone il reste masque : la conversation occupe la hauteur
         utile, et empiler cinq liens sous le composeur reprendrait la place
         que TASK-1496 avait justement rendue. --}}
    <div class="bpsf-footer">
        @include('partials.footer')
    </div>

    {{-- TASK-1496 : la mention de confidentialite de page est RETIREE.
         Mesure au navigateur : elle occupait 54 px sous le composeur en 390,
         juste sous `.bpgs-foot` qui dit deja « Echange public sans compte. Ne
         partagez pas de donnees personnelles. » Deux phrases pour la meme
         chose, et 97 px de page utile perdus. Le Shell porte deja sa mention :
         c'est la bonne, elle est dans le cadre de la conversation. --}}
    {{-- TASK-1500 : Alpine arrive avec Livewire, et Livewire ne s'injecte que
         lorsqu'un de ses composants est rendu. Cette page n'en rend aucun :
         mesure au navigateur, `window.Alpine` etait `undefined` alors que
         `app.js` etait bien charge — les bascules du rail cliquaient dans le
         vide. `layouts/app` fait exactement ceci en fin de body. --}}
    @livewireScripts
</body>
</html>
