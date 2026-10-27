<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bouclepro_demo' => [
        'url' => 'https://lastprod.com/bouclepro-prototype/',
        'supported_locales' => ['fr', 'en'],
    ],

    /*
    |--------------------------------------------------------------------------
    | MailHog (developpement local uniquement)
    |--------------------------------------------------------------------------
    |
    | TASK-1376 — l'adresse de l'INTERFACE, distincte de celle de la sonde.
    |
    | La sonde part du serveur, donc de l'interieur de WSL : `127.0.0.1` y est
    | correct et reste une CONSTANTE dans le code.
    |
    | Le lien, lui, est clique depuis le navigateur de l'humain — souvent sur
    | Windows, ou `127.0.0.1` designe une AUTRE machine. D'ou une valeur de
    | configuration, jamais une URL construite depuis une requete.
    |
    */
    'mailhog' => [
        'ui_url' => env('MAILHOG_UI_URL', 'http://127.0.0.1:8025'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile (TASK-1713)
    |--------------------------------------------------------------------------
    |
    | Captcha pose a l'ENTREE de l'inscription. TASK-1712 avait ferme la
    | SORTIE — un nom qui est une publicite ne s'affiche plus ; le compte etait
    | quand meme cree.
    |
    | `enabled` est a `false` par defaut, et ce defaut est delibere :
    |
    |   - 39 POST `/register` existent dans 8 fichiers de tests sans aucun
    |     rapport avec le captcha ; un defaut `true` les rougirait tous ;
    |   - la PROD ne porte aujourd'hui aucune de ces variables : un defaut
    |     `true` fermerait l'inscription a la seconde ou `main` migre.
    |
    | La fonctionnalite part donc DORMANTE, et son activation est un geste
    | d'environnement — c'est aussi le coupe-circuit d'incident qui rend le
    | fail-closed de `TurnstileVerifier` tenable.
    |
    | `site_key` n'est PAS un secret : Cloudflare le publie dans le HTML du
    | widget. `secret_key` en est un, et ne quitte jamais le serveur.
    |
    */
    'turnstile' => [
        'enabled' => (bool) env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),

        // Point de terminaison officiel. Configurable pour qu'un banc de test
        // puisse le detourner, jamais pour etre change en PROD.
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),

        // Un captcha est sur le chemin critique d'un formulaire : au-dela de
        // quelques secondes, l'humain a deja renonce. Mesure locale : 0,16 s.
        'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),
    ],

];
