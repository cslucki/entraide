<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => env('STORAGE_PUBLIC_DRIVER', env('AWS_ACCESS_KEY_ID') ? 's3' : 'local'),
            'root' => storage_path('app/public'),
            'url' => env('FILESYSTEM_PUBLIC_URL', env('AWS_PUBLIC_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/storage')),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PUBLIC_BUCKET', env('AWS_BUCKET')),
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'public_s3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_PUBLIC_BUCKET', env('AWS_BUCKET')),
            'url' => env('AWS_PUBLIC_URL'),
            'path' => env('AWS_PUBLIC_PATH'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'dossier_files' => [
            'driver' => env('DOSSIER_FILES_DRIVER', 'local'),
            'root' => storage_path('app/private/dossier-files'),
            'serve' => true,
            'url' => '/dossier-files',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,

            /*
             * Un repertoire prive de Flysystem vaut 0700 par defaut. Le
             * processus qui SERT les fichiers n'est pas celui qui les ECRIT :
             * une piece deposee en console, ou rejouee par un ScenarioPack,
             * atterrit alors dans une arborescence qu'Apache ne peut pas
             * traverser. Les fichiers eux-memes sont lisibles (0644) : c'est
             * le chemin qui bloque.
             *
             * Et le symptome ment. `DossierFileController::preview()` rattrape
             * l'exception de lecture en `abort(404)` : une source citee par
             * l'IA se presente comme ABSENTE alors qu'elle est seulement
             * inaccessible. La promesse « une source citee s'ouvre » se casse
             * sans qu'aucune trace ne dise pourquoi.
             *
             * 0770 rend le chemin franchissable par le groupe proprietaire —
             * celui du serveur web — sans jamais l'ouvrir au reste du monde.
             * C'est le mode que portent deja les repertoires sains du banc.
             * Cle ignoree par le driver s3.
             */
            'permissions' => [
                'dir' => [
                    'public' => 0755,
                    'private' => 0770,
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
