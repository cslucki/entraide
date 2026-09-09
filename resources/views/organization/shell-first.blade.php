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
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $organization->name }}</title>
    <link rel="icon" href="{{ asset('brand/bouclepro-symbol-64.png') }}">
    @vite(['resources/css/app.css'])
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
    </style>
</head>
<body class="bpsf-page">
    <header class="bpsf-bar">
        {{-- Le logo, puis le nom. L'Organization par defaut S'APPELLE BouclePro :
             afficher « BouclePro · BouclePro » etait le rendu mesure au
             navigateur, le nom n'est donc repete que s'il apporte quelque
             chose. C'est la SEULE barre de la page : l'entete interne du Shell
             est masque en shell_first. --}}
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

    {{-- TASK-1496 : la mention de confidentialite de page est RETIREE.
         Mesure au navigateur : elle occupait 54 px sous le composeur en 390,
         juste sous `.bpgs-foot` qui dit deja « Echange public sans compte. Ne
         partagez pas de donnees personnelles. » Deux phrases pour la meme
         chose, et 97 px de page utile perdus. Le Shell porte deja sa mention :
         c'est la bonne, elle est dans le cadre de la conversation. --}}
</body>
</html>
