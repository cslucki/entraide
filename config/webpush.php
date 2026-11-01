<?php

/*
|--------------------------------------------------------------------------
| TASK-1739 — Web Push : identite VAPID du serveur
|--------------------------------------------------------------------------
|
| VAPID (RFC 8292) est la facon dont un serveur se NOMME aupres d'un
| fournisseur Push. La paire de clefs n'authentifie pas le destinataire : elle
| prouve au fournisseur que l'envoi vient bien de nous, et lui donne une
| adresse de contact en cas d'abus.
|
| ## Ce que cette TASK en fait, et ce qu'elle n'en fait pas
|
| Elle n'envoie RIEN (CDC 5.2). Seule la clef PUBLIQUE est employee ici, et
| seulement cote navigateur : `pushManager.subscribe()` l'exige en
| `applicationServerKey`, sinon le fournisseur refuse l'abonnement.
|
| La clef PRIVEE n'est lue par personne dans cette TASK. Elle est declaree
| maintenant pour que la paire soit coherente et generee une seule fois — mais
| son premier usage appartient au chantier d'ENVOI.
|
| ## La clef privee ne quitte jamais le serveur
|
| Elle n'est exposee par aucune route, aucune vue, aucun journal. Le service
| qui sert la configuration au navigateur ne rend QUE la clef publique, et un
| test l'atteste. C'est la seule garde qui compte ici : une clef privee VAPID
| divulguee permet a n'importe qui d'envoyer des notifications sous notre
| identite.
|
| ## Une paire PAR ENVIRONNEMENT
|
| Jamais la meme en local, en recette et en production. Les abonnements d'un
| navigateur sont lies a la clef publique qui les a crees : changer de paire
| invalide tous les abonnements existants, et partager une paire entre
| environnements laisserait un banc de test emettre sous l'identite de la
| production.
|
| Aucune clef n'est posee dans Laravel Cloud par cette TASK (DEC-05 du CDC :
| aucune permission implicite).
|
*/

return [

    'vapid' => [
        /*
         * La clef publique, en base64url, point P-256 non compresse
         * (65 octets, premier octet 0x04). Elle N'EST PAS secrete :
         * le navigateur la recoit, c'est sa raison d'etre.
         */
        'public_key' => env('VAPID_PUBLIC_KEY'),

        /*
         * La clef privee, en base64url (scalaire de 32 octets).
         * SECRETE. Lue uniquement par le serveur, au chantier d'envoi.
         */
        'private_key' => env('VAPID_PRIVATE_KEY'),

        /*
         * L'adresse de contact transmise au fournisseur, sous forme
         * `mailto:` ou `https:`. Elle doit etre REELLE et surveillee : c'est
         * par la qu'un fournisseur signale un abus avant de nous bloquer.
         */
        'subject' => env('VAPID_SUBJECT'),
    ],

    /*
     * Le Push est-il propose a l'interface ?
     *
     * Un interrupteur d'EXPLOITATION, distinct de la presence des clefs. Il
     * permet de retirer la proposition sans toucher a la configuration
     * cryptographique — et de ne jamais promettre une capacite qui n'est pas
     * operationnelle (CDC section 3.2 : « ne pas promettre la reception de
     * Push si elle n'est pas encore livree »).
     *
     * Par defaut FAUX : une variable absente ne doit pas allumer une
     * fonctionnalite. Meme doctrine que `PWA_INSTALL_POLICY` en T1737.
     */
    'enabled' => (bool) env('WEB_PUSH_ENABLED', false),

];
