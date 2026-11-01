{{--
    TASK-1737 — `/installer`, l'ecran d'installation atteignable directement.

    ## Pourquoi un document autonome

    Cette page doit s'afficher vite, partout, et sans rien attendre : c'est la
    destination du bandeau, le repli des plateformes sans invite native (iOS,
    Firefox) et l'URL qu'on ouvre sur un telephone pour recetter. Lui faire
    emprunter `x-app-layout` l'aurait chargee du rail, de la barre mobile, du
    bundle complet et de la resolution de tenant — pour un ecran qui n'a besoin
    d'aucun des quatre.

    ## La regle de cette page : elle ne doit JAMAIS etre vide

    Ici la superposition **EST** le contenu — il n'y a rien dessous. Toute
    situation ou l'ecran n'apparait pas rend donc un ecran vide, et c'est le
    defaut que la recette du 31/10/2026 a remonte DEUX FOIS, par deux portes
    differentes. On ferme la classe, pas les instances : il y a deux replis,
    pour les deux causes possibles, et aucune troisieme n'existe.

    **Cause A — l'ecran est emis mais masque.** `<x-pwa-install />` est cache
    par `@media (display-mode: standalone)` : une PWA installee ne doit jamais
    revoir l'ecran d'installation (CDC 3.3). Or `/installer` reste une URL,
    ouvrable DEPUIS l'application installee par un lien, un signet ou un retour
    arriere. Le repli ordinaire prend alors le relais, en CSS pure — aucun
    JavaScript ne decide, donc aucun flash.

    **Cause B — l'ecran n'est pas emis du tout.** Avec
    `PWA_INSTALL_POLICY=off`, `PwaInstallContext` ne rend rien, et le repli de
    la cause A est justement masque en mode navigateur : la page etait
    integralement vide. Le repli `--seul` couvre ce cas ; il n'est pas soumis a
    la regle de masquage, et il porte un lien de retour, parce qu'une page qui
    ne peut rien proposer doit au moins proposer de partir.

    Le renoncement, lui, ne peut plus vider cette page : `PwaInstallContext`
    place la route dediee AVANT le cookie, pour la raison ecrite dans son
    docbloc.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ __('pwa.title') }}</title>

    {{-- Cette page invite a installer : qu'elle n'apparaisse pas dans les
         resultats de recherche. Ce n'est pas du contenu, c'est un geste. --}}
    <meta name="robots" content="noindex, follow">

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">

    {{-- TASK-1737 — le contrat d'installabilite PWA, declare une seule fois. --}}
    <x-pwa-head />
    <x-pwa-boot />

    {{-- `--bp-primary` pour que l'appel a l'action porte la couleur du theme. --}}
    <x-theme-tokens />

    <style>
        html, body {
            margin: 0;
            min-height: 100%;
            background: #0b0b10;
            color: #fff;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        /* Le repli : visible SEULEMENT quand l'ecran d'installation ne l'est
           pas, c'est-a-dire depuis l'application deja installee. */
        .bp-installer-fallback {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .bp-installer-fallback p { margin: 0; font-size: .95rem; line-height: 1.55; color: rgba(255, 255, 255, .72); }
        .bp-installer-fallback strong { display: block; margin-bottom: .4rem; font-size: 1.1rem; color: #fff; }

        .bp-installer-fallback a {
            color: #fff;
            font-size: .95rem;
            text-decoration: underline;
            text-underline-offset: .2em;
        }

        /* Le repli de la cause A cede la place a l'ecran d'installation en mode
           navigateur. Celui de la cause B — `--seul` — ne cede a RIEN : il est
           emis precisement parce qu'aucun ecran ne vient. Le masquer ici
           rendrait la page vide, qui est le defaut qu'on ferme. */
        @media all and (display-mode: browser) {
            .bp-installer-fallback:not(.bp-installer-fallback--seul) { display: none; }
        }
    </style>
</head>
<body>
    @php
        $pwaInstall = \App\Support\Pwa\PwaInstallContext::for(request());
    @endphp

    @if ($pwaInstall->emetQuelqueChose())
        {{-- Cause A : l'ecran vient. Ce repli ne se voit que depuis
             l'application installee, ou la CSS le demasque. --}}
        <div class="bp-installer-fallback">
            <p>
                <strong>{{ __('pwa.installing_title') }}</strong>
                {{ __('pwa.installing_body') }}
            </p>
        </div>

        <x-pwa-install />
    @else
        {{-- Cause B : aucun ecran ne vient (kill-switch `off`). Cette page
             reste atteignable par un signet ou un lien : elle dit ce qui est
             vrai, et elle offre de partir. --}}
        <div class="bp-installer-fallback bp-installer-fallback--seul">
            <p>
                <strong>{{ __('pwa.unavailable_title') }}</strong>
                {{ __('pwa.unavailable_body') }}
            </p>
            <p><a href="{{ $pwaInstall->urlRetour() }}">{{ __('pwa.back_to_site') }}</a></p>
        </div>
    @endif
</body>
</html>
