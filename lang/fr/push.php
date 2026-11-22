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
    'ready_none' => 'Votre appareil est prêt, mais aucune notification n’est encore activée : cochez « Sur mes appareils » dans vos réglages par événement, puis enregistrez.',
    'ready_some' => 'Votre appareil est prêt. Vous recevrez les notifications cochées « Sur mes appareils » dans vos réglages par événement.',
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

    // TASK-1877 — la page dédiée et son test Web Push RÉEL.
    //
    // Arbitrage MASTER (R2) : un fournisseur qui accepte n'a rien AFFICHÉ.
    // Aucun de ces textes ne dit « reçue » : c'est la personne qui vérifie.
    'device_back' => '← Retour aux réglages des notifications',
    'device_settings_button' => 'Réglages sur cet appareil',
    'test_button' => 'Envoyer une notification de test',
    'test_payload_title' => 'Notification de test',
    'test_payload_body' => 'Si vous voyez ce message, les notifications de BouclePro arrivent sur cet appareil.',
    'test_sent' => 'Notification de test envoyée. Vérifiez sa réception sur cet appareil.',
    'test_failed' => 'L’envoi a échoué : le service de notifications n’a pas accepté le message. Réessayez dans un instant ; si cela persiste, désactivez puis réactivez les notifications sur cet appareil.',
    'test_revoked' => 'Abonnement expiré. Réactivez les notifications.',
    'test_revoked_cleanup_failed' => 'Abonnement expiré, mais votre navigateur n’a pas pu l’oublier : cet appareil ne recevra pas de notification. Rechargez la page puis réactivez les notifications.',
    'test_unknown' => 'Cet appareil n’est pas reconnu comme abonné à votre compte. Désactivez puis réactivez les notifications sur cet appareil.',
    'test_throttled' => 'Trop d’essais rapprochés. Patientez une minute avant de recommencer.',

    // TASK-1877 — « Diagnostic avancé », publié (option B MASTER), replié.
    // `%s` est remplacé en JavaScript (voir push-device.js).
    'diag' => [
        'title' => 'Diagnostic avancé',
        'permission' => 'Autorisation',
        'origin' => 'Origine',
        'secure' => 'Contexte sûr',
        'scope' => 'Portée du Service Worker',
        'version' => 'Service Worker actif',
        'subscription' => 'Abonnement',
        'fingerprint' => 'Empreinte',
        'server' => 'Connu du serveur',
        'key' => 'Clé d’abonnement',
        'test_local' => 'Tester l’affichage local',
        'simulate' => 'Simuler une notification',
        'api_absent' => 'API absente',
        'yes' => 'oui',
        'no' => 'NON',
        'none' => 'aucun',
        'no_answer' => '(pas de réponse)',
        'push_supported' => 'push pris en charge',
        'sw_old' => 'ANCIEN — ne répond pas au diagnostic',
        'server_silent' => '(serveur muet)',
        'key_hidden' => '(non exposée par ce navigateur)',
        'key_match' => 'concorde (%s…)',
        'key_diverge' => 'DIVERGE — abonnement %s… ≠ serveur %s…',
        'permission_refused' => 'Autorisation refusée : rien ne peut s’afficher.',
        'local_title' => 'Affichage local',
        'local_body' => 'Si vous voyez ceci, l’appareil sait afficher une notification.',
        'local_done' => 'Demandé. Visible ? Alors l’affichage système fonctionne.',
        'local_failed' => 'Échec de l’affichage local.',
        'simulate_needs_permission' => 'Autorisation non accordée : commencez par « Tester l’affichage local ».',
        'simulate_title' => 'Notification simulée',
        'simulate_body' => 'Traitée par le Service Worker actif, sans réseau.',
        'simulate_old' => 'Le Service Worker actif ne connaît pas ce diagnostic : exemplaire ancien. Fermez puis rouvrez l’application.',
        'simulate_done' => 'Le Service Worker actif a affiché la notification : le chemin de réception est intact.',
        'simulate_refused' => 'Le Service Worker a refusé la notification.',
    ],
];
