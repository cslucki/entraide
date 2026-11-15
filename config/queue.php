<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        /*
        | TASK-1829/1830 — la connexion Laravel Cloud Managed Queue
        | (Starter), SQS sous le capot. Elle n'est JAMAIS la connexion par
        | defaut : seuls `SendNotificationEmail` et `SendNotificationWebPush`
        | y basculent, et seulement si `NOTIFICATIONS_MANAGED_QUEUE_CONNECTION`
        | est posee (voir leurs docblocks).
        |
        | TASK-1830 — PLACEHOLDER, verifie contre la doc officielle
        | (laravel.com/cloud/docs/queues) : elle ne documente AUCUN nom de
        | variable d'environnement pour les identifiants de cette connexion.
        | « Laravel Cloud automatically sets queue credentials and
        | connection » — l'injection est entierement geree par la
        | plateforme, HORS de ce fichier. Les cles `key`/`secret`/`prefix`
        | ci-dessous ne sont donc PAS celles que Cloud remplira : elles
        | existent uniquement pour que le NOM de connexion `cloud` resolve
        | sans erreur en local (ou rien ne le sollicite jamais, le drapeau
        | etant vide par defaut). Ne JAMAIS supposer que Cloud lit
        | `CLOUD_QUEUE_PREFIX`/`CLOUD_QUEUE_NAME`/`CLOUD_QUEUE_SUFFIX` — ces
        | trois noms n'existent dans AUCUNE doc Laravel Cloud.
        |
        | `queue`, lui, est REEL de notre cote : c'est le nom de file que
        | NOUS choisissons pour les deux jobs de notification (voir
        | `notifications_managed_queue_name` plus bas) — Cyril devra creer
        | la Managed Queue sous ce MEME nom au tableau de bord (etape
        | volontairement HORS de cette TASK).
        */
        'cloud' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('NOTIFICATIONS_MANAGED_QUEUE_NAME', 'notifications'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Managed Queue — routage conditionnel des notifications (TASK-1829/1830)
    |--------------------------------------------------------------------------
    |
    | `notifications_managed_queue_connection` vide par defaut :
    | `SendNotificationEmail` et `SendNotificationWebPush` n'appellent alors
    | AUCUN `onConnection()`/`onQueue()` different de leur file DEDIEE
    | actuelle, et `QUEUE_CONNECTION` continue seul de gouverner —
    | comportement INCHANGE, en local comme en PROD aujourd'hui. Ne poser
    | `cloud` ici qu'APRES la creation reelle de la file managee (etape
    | hors de cette TASK, avec Cyril au tableau de bord).
    |
    | `notifications_managed_queue_name` — TASK-1830 : le plan Starter de
    | la Managed Queue n'offre qu'UNE SEULE file par environnement ("Each
    | managed queue handles one queue name", doc officielle Laravel
    | Cloud). Les deux jobs, qui portent chacun leur propre nom de file
    | DEDIE sous `database`/`sync`, doivent donc converger sur ce nom
    | PARTAGE des que la connexion bascule sur `cloud` — jamais avant. La
    | Managed Queue devra etre creee sous ce MEME nom au tableau de bord.
    |
    */

    'notifications_managed_queue_connection' => env('NOTIFICATIONS_MANAGED_QUEUE_CONNECTION'),

    'notifications_managed_queue_name' => env('NOTIFICATIONS_MANAGED_QUEUE_NAME', 'notifications'),

];
