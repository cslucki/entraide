{{--
    TASK-1737 — `/installer`, l'ecran d'installation atteignable directement.

    ## Pourquoi un document autonome

    Cette page doit s'afficher vite, partout, et sans rien attendre : c'est la
    destination du bandeau, le repli des plateformes sans invite native (iOS,
    Firefox) et l'URL qu'on ouvre sur un telephone pour recetter. Lui faire
    emprunter `x-app-layout` l'aurait chargee du rail, de la barre mobile, du
    bundle complet et de la resolution de tenant — pour un ecran qui n'a besoin
    d'aucun des quatre.

    ## Le repli visible, et pourquoi il existe

    `<x-pwa-install />` est masque par `@media (display-mode: standalone)` : une
    PWA installee ne doit jamais revoir l'ecran d'installation (CDC 3.3). Mais
    `/installer` reste une URL : quelqu'un peut l'ouvrir DEPUIS l'application
    installee — par un lien, un signet, ou en revenant en arriere.

    Sans ce bloc, il verrait une page VIDE. Le repli dit donc ce qui est vrai
    dans ce cas, et il est masque dans le cas inverse. Les deux branches sont
    en CSS pure : aucun JavaScript ne decide, donc aucun flash.
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
            min-height: 100vh;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            text-align: center;
        }

        .bp-installer-fallback p { margin: 0; font-size: .95rem; line-height: 1.55; color: rgba(255, 255, 255, .72); }
        .bp-installer-fallback strong { display: block; margin-bottom: .4rem; font-size: 1.1rem; color: #fff; }

        @media all and (display-mode: browser) {
            .bp-installer-fallback { display: none; }
        }
    </style>
</head>
<body>
    <div class="bp-installer-fallback">
        <p>
            <strong>{{ __('pwa.installing_title') }}</strong>
            {{ __('pwa.installing_body') }}
        </p>
    </div>

    <x-pwa-install />
</body>
</html>
