<?php

/*
|--------------------------------------------------------------------------
| TASK-1873 — variantes optimisees des images televersees
|--------------------------------------------------------------------------
|
| Distinct de `config/image.php`, qui appartient a Intervention Image (pilote,
| orientation). Ici : QUOI produire, pour QUELLE surface, et dans quelles
| limites.
|
| ## Versionner un preset
|
| Changer les dimensions, le mode ou la qualite d'un preset SANS incrementer
| sa `version` ne regenere rien : le registre considere une variante a jour
| tant que sa version et l'empreinte de sa source n'ont pas change. Incrementer
| `version` rend toutes les variantes de ce preset perimees ; elles se
| regenerent au prochain lot de rattrapage, et l'ancienne variante (fichier
| DERIVE) est retiree une fois la nouvelle prete. L'original n'est jamais
| touche.
|
| ## Modes
|
|   `cover`     recadrage centre a la taille exacte — avatars UNIQUEMENT ;
|   `scaleDown` reduction proportionnelle, jamais d'agrandissement, jamais de
|               recadrage — couvertures (texte eventuel preserve), logos,
|               services, contenu. `height` null = seule la largeur borne.
|
*/

return [

    'disk' => 'public',

    // Repertoire racine des variantes, sur le meme disque que les originaux.
    'directory' => 'image-variants',

    'quality' => (int) env('IMAGE_VARIANTS_QUALITY', 80),

    /*
     * Garde AVANT decodage. GD decode en bitmap (~4 octets par pixel) : une
     * photo de 25 Mpx coute deja ~100 Mo, et la PROD mesuree tourne sur
     * 512 Mo. Au-dela, l'image est marquee `skipped` et l'original reste
     * servi — jamais de tentative qui ferait tomber le worker.
     */
    'max_pixels' => (int) env('IMAGE_VARIANTS_MAX_PIXELS', 25_000_000),
    'max_side' => (int) env('IMAGE_VARIANTS_MAX_SIDE', 10_000),
    'max_source_bytes' => (int) env('IMAGE_VARIANTS_MAX_SOURCE_BYTES', 20 * 1024 * 1024),

    // Plafond PLUS BAS pour la seule derivation faite dans une requete HTTP
    // (images TipTap) : la memoire web est souvent bornee (128 Mo en local).
    // Au-dela, l'article garde l'URL de l'original, toujours valide.
    'in_request_max_pixels' => (int) env('IMAGE_VARIANTS_IN_REQUEST_MAX_PIXELS', 12_000_000),

    /*
     * Connexion de queue des jobs d'images. Vide = connexion par defaut —
     * `sync` en PROD (mesure 21/11/2026) : la garde `ImageQueue` refuse alors
     * les lots et ne derive rien a l'upload (original servi, avertissement
     * journalise). En PROD, `database` + un worker dedie a `images` sont des
     * PREREQUIS de release (TASK-1874), non configures par cette TASK. En
     * local, `database` + `ai/scripts/images-queue-worker.sh`, sans basculer
     * les autres queues locales.
     */
    'queue_connection' => env('IMAGE_VARIANTS_QUEUE_CONNECTION') ?: null,

    // Formats matriciels traites. SVG (banque d'avatars Scenario) et GIF
    // (animations) sont volontairement exclus : l'original est servi.
    'mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    'presets' => [
        'avatar' => ['mode' => 'cover', 'width' => 192, 'height' => 192, 'version' => 1],
        'logo' => ['mode' => 'scaleDown', 'width' => 256, 'height' => 256, 'version' => 1],
        'cover_sm' => ['mode' => 'scaleDown', 'width' => 480, 'height' => null, 'version' => 1],
        'cover_md' => ['mode' => 'scaleDown', 'width' => 960, 'height' => null, 'version' => 1],
        'cover_lg' => ['mode' => 'scaleDown', 'width' => 1600, 'height' => null, 'version' => 1],
        'service_thumb' => ['mode' => 'scaleDown', 'width' => 400, 'height' => 400, 'version' => 1],
        'service_full' => ['mode' => 'scaleDown', 'width' => 1200, 'height' => 1200, 'version' => 1],
        'content' => ['mode' => 'scaleDown', 'width' => 1600, 'height' => null, 'version' => 1],
    ],

    /*
     * Surface = une famille d'images televersees. `backfill` dit si la
     * commande et la console la rattrapent : les images de contenu (TipTap)
     * sont inscrites en dur dans le HTML des articles, qu'on ne reecrit pas.
     */
    'surfaces' => [
        'user_avatar' => ['presets' => ['avatar'], 'backfill' => true],
        'organization_logo' => ['presets' => ['logo'], 'backfill' => true],
        'loop_cover' => ['presets' => ['cover_sm', 'cover_md', 'cover_lg'], 'backfill' => true],
        'blog_cover' => ['presets' => ['cover_sm', 'cover_md', 'cover_lg'], 'backfill' => true],
        'service_image' => ['presets' => ['service_thumb', 'service_full'], 'backfill' => true],
        'blog_content' => ['presets' => ['content'], 'backfill' => false],
    ],

    // Plafond d'un lot lance depuis la console : jamais de traitement massif
    // en un clic. 50 a la demande de Cyril (21/11).
    'console_max_batch' => (int) env('IMAGE_VARIANTS_CONSOLE_MAX_BATCH', 50),

];
