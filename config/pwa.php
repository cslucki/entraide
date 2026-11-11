<?php

/*
|--------------------------------------------------------------------------
| TASK-1737 — Progressive Web App : installation
|--------------------------------------------------------------------------
|
| Deux valeurs, et une seule raison d'exister pour chacune.
|
| ## `theme_color` — une valeur, deux endroits, et un test qui le prouve
|
| Mesure du 31/10/2026 : la couleur de chrome du navigateur etait declaree
| TROIS fois, avec TROIS valeurs differentes :
|
|   - `layouts/app` et `layouts/guest`  : `<meta name="theme-color">` = #1B1FCC
|   - `public/site.webmanifest`          : `theme_color`              = #050505
|   - `config/bouclepro_themes.php`      : `primary` des themes       = #0B4DFF, #527DFF, ...
|
| Le manifest gouverne la barre de titre de l'application INSTALLEE, la meta
| gouverne le chrome du navigateur : les deux doivent dire la meme chose, sinon
| l'application change de couleur en etant installee.
|
| La valeur retenue est #1B1FCC, et le choix est celui du MOINDRE RISQUE : elle
| est deja servie a tout le monde, sur toutes les pages applicatives, depuis
| toujours. Aligner la meta sur le manifest aurait au contraire repeint le
| chrome de chaque page pour tous les utilisateurs — un changement visible, pour
| un gain nul.
|
| Les `primary` de `bouclepro_themes.php` ne sont volontairement PAS candidats :
| ils varient par Organization, et le chrome du navigateur n'est pas une surface
| de tenant. C'est la meme doctrine que `x-theme-tokens` (TASK-1471), qui refuse
| qu'une preference de theme laissee par une Organization en repeigne une autre.
|
| C'est ce fichier qui fait desormais autorite, et `TASK1737PwaInstallationTest`
| verifie que `public/site.webmanifest` dit la meme chose. La divergence cesse
| donc d'etre possible sans rougir.
|
| ## `install.policy` — la politique de presentation, par SURFACE
|
| Arbitrage Cyril du 31/10/2026 (DEC-02bis) : plein ecran sur les entrees
| applicatives, bandeau non bloquant sur les vitrines publiques indexees.
|
| En configuration et non en base, DELIBEREMENT : cette TASK doit etre la plus
| courte qui permette de recetter l'installation sur un telephone. Pas de table,
| pas de colonne, donc aucune entree aux deux gardes de completude de cle
| etrangere (`UserDataLifecycleRegistry`, `ScenarioSandboxPreflight`). Une
| colonne par Organization reste possible plus tard sans rien casser.
|
| `PWA_INSTALL_POLICY=off` est le KILL-SWITCH : il eteint l'ecran partout, sans
| toucher une ligne de code. Les enrichissements du manifest, eux, survivent —
| c'est voulu, ils n'ont aucun effet de bord visible.
|
*/

return [

    /*
    | La couleur de chrome. Lue par `<x-pwa-head />` et verifiee contre
    | `public/site.webmanifest`. Ne jamais la dupliquer ailleurs.
    */
    'theme_color' => '#1B1FCC',

    /*
    | Le manifest, nomme une fois. Le composant l'emet, le test l'ouvre.
    */
    'manifest_path' => '/site.webmanifest',

    /*
    | TASK-1805 — l'override explicite du nom PWA, pour un environnement de
    | preproduction qui voudrait un troisieme nom sans toucher au code. Le
    | CALCUL (local -> BoucleDev, le reste -> BouclePro) vit dans
    | `App\Support\Pwa\PwaAppName`, jamais ici : ce fichier n'est traverse
    | qu'une fois, et y appeler `app()->environment()` figerait la decision
    | au mauvais moment pour les tests qui veulent l'exercer avec plusieurs
    | environnements.
    */
    'app_name_override' => env('PWA_APP_NAME'),

    'install' => [

        /*
        | `off` | `banner` | `install_first`
        |
        | Valide par `App\Support\Pwa\PwaInstallPolicy` : une valeur inconnue
        | retombe sur le defaut plutot que d'eteindre la fonction en silence.
        |
        | Le defaut est `banner` et non `install_first` : une variable absente
        | ne doit pas poser un interstitiel plein ecran sur les vitrines
        | publiques indexees sans que personne ne l'ait demande.
        */
        'policy' => env('PWA_INSTALL_POLICY', 'banner'),

        /*
        | Duree de vie du renoncement, en jours. « Continuer dans le
        | navigateur » n'est pas un refus definitif : le rappel revient, et
        | c'est ce qui remplace le mode bloquant ecarte par DEC-03.
        */
        'dismiss_days' => (int) env('PWA_INSTALL_DISMISS_DAYS', 30),

    ],
];
