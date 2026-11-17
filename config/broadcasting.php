<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'log' => [
            'driver' => 'log',
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST', 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com'),
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        // TASK-1850 — `client_options` VIDE laissait Laravel poser ses
        // propres defauts (`BroadcastManager::pusher()`) : `connect_timeout`
        // 10s, `timeout` 30s. Sous `QUEUE_CONNECTION=sync` (confirme en
        // PROD, TASK-1849), chaque appel `broadcast()` s'execute DANS la
        // requete HTTP de l'utilisateur qui envoie un message — une panne
        // Reverb aurait donc pu bloquer CETTE requete jusqu'a 30s avant que
        // le `catch(BroadcastException)` deja en place (TASK-1843/1845/1847)
        // puisse agir. Reverb est un appel HTTP interne, pas un service
        // tiers a latence variable : un aller-retour sain se mesure en
        // dizaines de ms en local (TASK-1848) et l'ordre de la centaine de
        // ms sur le cluster manage Laravel Cloud (meme region que l'app,
        // TASK-1849) — 1s de connexion et 2s de requete totale restent tres
        // au-dessus de ce plancher sain, tout en bornant le pire cas a une
        // fraction du defaut Laravel. Variables d'environnement, pas des
        // litteraux : ajustables sans redeploiement de code si la mesure en
        // conditions reelles (TASK-1851) montrait un besoin different.
        //
        // DEUX reglages distincts, mesure empiriquement (TASK-1850) avant
        // de les poser aux DEUX endroits : `client_options.connect_timeout`
        // configure le client Guzzle PARTAGE (`BroadcastManager::pusher()`)
        // et borne la phase de CONNEXION TCP — confirme suffisant seul,
        // Pusher.php ne le redefinit jamais par appel. `options.timeout`
        // (PAS `client_options.timeout`) est OBLIGATOIRE en plus : chaque
        // methode de `Pusher.php` (`post()`/`trigger()`/...) ECRASE
        // explicitement l'option de requete Guzzle `timeout` avec
        // `$this->settings['timeout']`, lu UNIQUEMENT depuis ce tableau
        // `options` (4e argument du constructeur `Pusher`) — jamais depuis
        // `client_options`. Sans cette seconde cle, une connexion ETABLIE
        // mais qui ne repond jamais (Reverb accroche, pas injoignable)
        // aurait quand meme attendu les 30s par defaut malgre
        // `client_options` configure : verifie en reproduisant les DEUX
        // scenarios (connexion refusee ET connexion ouverte sans reponse)
        // avant de figer ce correctif.
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'localhost'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                'timeout' => (float) env('REVERB_CLIENT_TIMEOUT', 2.0),
            ],
            'client_options' => [
                'connect_timeout' => (float) env('REVERB_CLIENT_CONNECT_TIMEOUT', 1.0),
                'timeout' => (float) env('REVERB_CLIENT_TIMEOUT', 2.0),
            ],
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
