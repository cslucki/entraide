<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sorties HTTP autorisees en RECETTE LOCALE
    |--------------------------------------------------------------------------
    |
    | TASK-1741 — `ExternalEgressGuard` refuse toute sortie HTTP des que la
    | base visee est le jumeau de production. Ces drapeaux rouvrent une porte
    | ETROITE, transport par transport, pour que `test.laravel` puisse eprouver
    | les fonctionnalites reelles de BouclePro.
    |
    | Ils sont a `false` par defaut, et `SortieDeRecette` exige en plus que
    | l'environnement soit `local` : la derogation est donc inatteignable en
    | production, meme si une variable trainait.
    |
    */

    'sorties_de_recette' => [

        // Les apercus de lien des ChatLoops, et EUX SEULS : la requete doit
        // porter le marqueur pose par `UrlPreviewService`, et la destination
        // rester publique au sens de `PublicHostPolicy`.
        'apercus_de_lien' => env('RECETTE_APERCUS_DE_LIEN', false),

        // TASK-1785 — l'outil d'import RSS, et LUI SEUL : la requete doit
        // porter le marqueur pose par `RssFeedFetcher`, et la destination
        // rester publique au sens de `PublicHostPolicy`.
        'import_rss' => env('RECETTE_IMPORT_RSS', false),

    ],

];
