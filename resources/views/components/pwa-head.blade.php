{{--
    TASK-1737 — LE contrat d'installabilite PWA, declare une seule fois.

    ## Pourquoi ce fichier existe

    C'est litteralement le probleme de TASK-1471, un etage plus haut.

    Le bloc qui rend une page installable — manifest, couleur de chrome, metas
    Apple, icone d'ecran d'accueil — vivait en DOUBLE dans `layouts/app` et
    `layouts/guest`, et les deux copies avaient DEJA diverge : `layouts/app`
    declarait `favicon-16x16`, `guest` non.

    Et surtout : les SIX gabarits d'entree autonomes n'en avaient AUCUN.

    Mesure du 31/10/2026, sur le tunnel public, origine `test.laravel` :

        GET /          -> 302 vers /org/main
        GET /org/main  -> 200, gabarit `hero-v2`
                          rel="manifest"               : 0
                          name="theme-color"           : 0
                          apple-mobile-web-app-capable : 0
                          enregistrement du SW         : 0

    Autrement dit : un smartphone qui arrive sur la racine de BouclePro atterrit
    sur un document ou l'installation est **techniquement impossible**. Pas
    difficile, pas mal presentee : impossible. Le navigateur n'a ni manifest ni
    Service Worker a se mettre sous la dent.

    C'est exactement l'echec que le docbloc de `x-theme-tokens` appelle « le
    pire des echecs » : un no-op SILENCIEUX. Rien ne plante, rien ne s'affiche
    en rouge, et la fonction n'existe simplement pas.

    ## Ce que ce composant ne fait pas

    **Il n'emet AUCUN JavaScript.** L'enregistrement du Service Worker reste ou
    il est (`resources/js/app.js`), l'ecran d'installation est un autre
    composant (`<x-pwa-install />`). Celui-ci ne fait que DECLARER.

    **Il ne decide rien.** La couleur vient de `config('pwa.theme_color')`,
    unique autorite, et `TASK1737PwaInstallationTest` verifie que
    `public/site.webmanifest` dit la meme chose. La divergence a trois valeurs
    releve ici ne peut plus revenir sans rougir.

    **Il ne touche pas au `viewport`.** Chaque document a le sien, et les
    landings autonomes n'ont pas `viewport-fit=cover` — le leur ajouter ferait
    passer leur contenu sous l'encoche, un changement visuel hors de cette
    TASK. `<x-pwa-install />` traite donc les safe areas defensivement, avec un
    repli qui vaut quelque chose quand `env(safe-area-inset-*)` rend zero.

    **Il ne porte pas les favicons.** Un favicon est l'icone d'un ONGLET, pas un
    contrat d'installation : les deux concerns restent separes, et le diff de
    cette TASK reste relisable. Seule l'`apple-touch-icon` est ici, parce
    qu'elle EST l'icone que iOS pose sur l'ecran d'accueil.

    ## Ou l'inclure

    Dans le `<head>` de tout document HTML complet destine au web. Le point
    d'insertion etait deja trouve : les six landings autonomes incluent DEJA
    `<x-theme-tokens />`. Une ligne a cote, et la page devient installable.

    `TASK1737PwaInstallationTest::test_every_routed_html_document_declares_the_pwa_head`
    balaie les routes et rougit si un gabarit l'oublie. C'est cette garde, et
    non la vigilance, qui empeche la situation d'aujourd'hui de se reproduire.

    Les gabarits d'EMAIL n'en recoivent jamais : un client de messagerie n'a
    rien a installer, et un `<link rel="manifest">` dans un email est au mieux
    ignore, au pire suspect.
--}}
<link rel="manifest" href="{{ config('pwa.manifest_path') }}">
<meta name="theme-color" content="{{ config('pwa.theme_color') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
{{-- TASK-1805 — le nom que iOS pose sous l'icone d'ecran d'accueil doit
     suivre le MEME nom que le manifest (`PwaAppName::current()`, jamais
     "BouclePro" code en dur ici une seconde fois), sinon iOS et
     Android/desktop divergeraient selon l'environnement. --}}
<meta name="apple-mobile-web-app-title" content="{{ \App\Support\Pwa\PwaAppName::current() }}">
{{-- TASK-1805 correctif (demande Cyril) — meme icone MARRON que le manifest
     en local (`public/apple-touch-icon-dev.png`), gouvernee par le MEME
     signal (`PwaAppName::isLocal()`) que `PwaManifestController`. --}}
<link rel="apple-touch-icon" sizes="180x180" href="{{ \App\Support\Pwa\PwaAppName::isLocal() ? '/apple-touch-icon-dev.png' : '/apple-touch-icon.png' }}">
