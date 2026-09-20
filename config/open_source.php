<?php

/*
 * TASK-1612 — le depot public, DECLARE.
 *
 * Tout ce que l'interface affiche comme identite du projet vit ici, jamais
 * dans la reponse de l'API GitHub. Deux raisons, mesurees avant d'ecrire :
 *
 *  1. `GET /repos/cslucki/entraide` renvoie
 *     `license: {"key":"other","spdx_id":"NOASSERTION","name":"Other"}`
 *     alors que le fichier `LICENSE` du depot est bien la GNU AGPL v3.
 *     Un badge « AGPL-3.0 » deduit de l'API n'aurait donc jamais pu
 *     s'allumer, ou aurait affiche « Other ».
 *
 *  2. L'owner (`cslucki`) ne doit apparaitre NULLE PART dans le rendu.
 *     Le garder dans une seule constante serveur rend la regle verifiable
 *     par grep : il n'existe qu'ici, et aucune vue ne lit ce fichier.
 */

return [

    /*
     * Coordonnees techniques du depot. Cotes SERVEUR uniquement : ni `owner`
     * ni l'URL complete ne traversent l'endpoint JSON ni les vues.
     */
    'repository' => [
        'owner' => env('OPEN_SOURCE_REPO_OWNER', 'cslucki'),
        'name' => env('OPEN_SOURCE_REPO_NAME', 'entraide'),

        /*
         * Arbitrage MASTER : la racine presentee est celle de la branche
         * PUBLIQUE par defaut. `develop` n'est jamais le contenu de
         * reference du drawer.
         */
        'branch' => env('OPEN_SOURCE_REPO_BRANCH', 'main'),
    ],

    /*
     * Identite affichee. Le depot s'appelle « entraide » sur GitHub ; le
     * produit l'appelle « BouclePro Core ».
     */
    'display_name' => 'BouclePro Core',

    /*
     * Badges. Declaratifs — voir la raison 1 en tete de fichier.
     * `language` reste, lui, lu sur l'API : GitHub le calcule honnetement.
     */
    'license' => 'AGPL-3.0',
    'stack' => ['Laravel', 'Livewire'],

    /*
     * Jeton GitHub. OPTIONNEL, jamais requis : sans lui l'API publique
     * suffit (60 requetes/h par IP, pour un budget de 14 appels par
     * rafraichissement horaire). S'il est pose, il monte simplement le
     * plafond a 5 000/h.
     */
    'token' => env('GITHUB_TOKEN'),

    'cache' => [
        // Duree de vie de l'instantane frais.
        'ttl_minutes' => 60,

        /*
         * Repli : le dernier instantane CONNU survit 24 h. C'est lui qui
         * evite l'etat « indisponible » quand GitHub tombe ou quand le
         * quota est epuise — l'interface montre alors une donnee datee
         * plutot que rien.
         */
        'stale_hours' => 24,

        /*
         * Verrou anti-stampede : si deux visiteurs ouvrent le drawer a la
         * seconde ou le cache expire, un seul part chercher, l'autre est
         * servi par le repli.
         */
        'lock_seconds' => 30,
    ],

    /*
     * Plafond d'appels sortants par rafraichissement, pose par MASTER.
     *
     * Compte reel mesure sur le depot : 1 (depot) + 1 (racine) +
     * 11 (dossiers de la racine) + 3 (compteurs) = 16. Le plafond en
     * refuse un, et le service sert dans l'ordre d'utilite : la racine et
     * ses messages d'abord, puis branches et commits, les tags en dernier.
     * C'est donc le compteur de tags qui tombe aujourd'hui — celui dont
     * MASTER a dit qu'il etait facultatif.
     *
     * Le service s'arrete NET a ce plafond : si la racine gagnait des
     * dossiers, ce sont des messages de commit qui manqueraient, jamais le
     * quota GitHub qui serait epuise.
     */
    'call_budget' => 15,

    'timeout_seconds' => 8,
];
