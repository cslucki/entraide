<?php

// TASK-1739 — abonnements Web Push, un par appareil.

return [
    'device_title' => 'Notifications sur cet appareil',
    'device_intro' => 'Recevez une notification lorsqu’une personne a besoin de vous, même quand BouclePro est fermé.',

    // Les quatre états réels. Aucun n'est une supposition : trois se lisent
    // dans le navigateur, le dernier vient du serveur.
    'state_on' => 'Activées sur cet appareil',
    'state_off' => 'Non activées sur cet appareil',
    'state_denied' => 'Refusées par votre navigateur',
    'state_unsupported' => 'Non prises en charge par ce navigateur',
    'state_unavailable' => 'Indisponible pour le moment',
    'state_checking' => 'Vérification…',

    'enable' => 'Activer les notifications',
    'disable' => 'Désactiver sur cet appareil',

    'denied_help' => 'Votre navigateur a refusé les notifications pour BouclePro. Pour les réactiver, ouvrez les réglages du site dans votre navigateur et autorisez les notifications, puis revenez sur cette page.',
    'unsupported_help' => 'Ce navigateur ne propose pas les notifications web. Sur iPhone, installez d’abord BouclePro sur votre écran d’accueil : les notifications ne sont disponibles que depuis l’application installée.',
    'unavailable_help' => 'Les notifications ne sont pas encore proposées sur cet espace. Votre navigateur, lui, sait les recevoir : il n’y a rien à faire de votre côté.',

    'devices_count' => '{0}Aucun appareil enregistré|{1}1 appareil enregistré|[2,*]:count appareils enregistrés',

    // On ne promet pas ce qui n'est pas livré (CDC 3.2).
    /*
     * TASK-1744 — `not_yet_sending` est MORT, et il a coûté une recette.
     *
     * T1739 l'écrivait avec raison : aucun canal `web_push` n'existait au
     * catalogue, s'abonner ne faisait donc rien arriver. T1744 ouvre le canal,
     * et la phrase est devenue l'inverse de la vérité — elle disait à la
     * personne qui venait d'activer son appareil qu'elle ne recevrait rien,
     * juste au-dessus des cases qu'il fallait justement cocher.
     *
     * Deux états désormais, parce que l'écran doit dire ce qui est VRAI pour
     * cette personne-là : son appareil est prêt, et soit rien n'est encore
     * coché, soit quelque chose l'est.
     */
    'ready_none' => 'Votre appareil est prêt, mais aucune notification n’est encore activée : cochez « Sur mes appareils » ci-dessous, puis enregistrez.',
    'ready_some' => 'Votre appareil est prêt. Vous recevrez les notifications cochées « Sur mes appareils » ci-dessous.',
    'ready_none_action' => 'Aller aux réglages par événement',

    'enable_failed' => 'L’activation n’a pas abouti. Réessayez dans un instant.',
    'disable_failed' => 'La désactivation n’a pas abouti. Réessayez dans un instant.',
    'disabled' => 'Les notifications Push ne sont pas disponibles pour le moment.',
    'key_stale' => 'Cet appareil a été abonné avec une clé de sécurité qui n’a plus cours : il ne peut plus recevoir de notification. Cliquez sur « Activer » pour le réabonner.',

    // TASK-1821 — recette LOCALE uniquement : identifier l'abonnement de CE
    // navigateur sans deviner — libellé et empreinte déjà en base, jamais
    // de nouvelle collecte. `%label%` et `%fingerprint%` sont remplacés en
    // JavaScript, jamais par le serveur (voir push-device.js).
    'device_registered' => 'Appareil enregistré : %label% · #%fingerprint%',
    'device_registered_unlabeled' => 'Appareil enregistré : #%fingerprint%',
];
