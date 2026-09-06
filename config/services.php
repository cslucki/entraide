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

];
