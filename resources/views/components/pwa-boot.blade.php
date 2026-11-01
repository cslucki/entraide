{{--
    TASK-1737 — l'enregistrement du Service Worker, declare une seule fois.

    ## Pourquoi il sort de `app.js`

    L'enregistrement vivait dans `resources/js/app.js` (6 lignes, tout en bas).
    Consequence mesuree le 31/10/2026 : les gabarits d'entree autonomes ne
    chargent pas ce bundle, donc `/org/main` n'enregistrait **aucun** Service
    Worker — zero occurrence de `serviceWorker` dans la page servie.

    Et sans Service Worker enregistre, Chrome ne declenche **jamais**
    `beforeinstallprompt` : le bouton « Installer » n'aurait rien a appeler.
    Declarer le manifest sur ces pages etait donc necessaire mais pas
    suffisant. C'est l'autre moitie du defaut P0.

    Le charger via `@vite(['resources/js/app.js'])` sur ces gabarits aurait
    « marche » et casse leur mise en page : `app.css` embarque le preflight
    Tailwind, et ces pages portent une CSS ecrite a la main
    (`public/css/ofsh-hero.css` et ses voisines). On aurait repeint cinq
    vitrines pour six lignes de JavaScript.

    L'enregistrement devient donc un composant, et il quitte `app.js` plutot que
    d'y etre recopie : **une seule autorite**, la meme doctrine que
    `<x-pwa-head />` et que `x-theme-tokens` avant eux. Effet de bord utile : il
    ne depend plus de l'analyse d'un bundle de 8 400 lignes.

    ## Ce qu'il ne fait pas

    Il ne change **aucune** strategie de cache. `public/sw.js` garde
    integralement le durcissement de TASK-1692 : documents en reseau seul,
    liste blanche d'assets statiques, et rien d'autre ne passe. Ce composant
    enregistre, il ne decide pas ce qui est mis en cache.

    `register()` est idempotent pour un meme couple script/scope : l'inclure sur
    toutes les pages ne cree pas des enregistrements concurrents.

    L'echec est avale volontairement. Un Service Worker est une AMELIORATION :
    sur un certificat auto-signe, en navigation privee ou sur un contexte non
    securise, l'enregistrement echoue — et aucune de ces situations ne doit
    casser la page ni salir la console de l'utilisateur.
--}}
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            try {
                navigator.serviceWorker.register('/sw.js').catch(function () {});
            } catch (e) {}
        });
    }
</script>
