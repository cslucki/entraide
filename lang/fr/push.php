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
    'state_checking' => 'Vérification…',

    'enable' => 'Activer les notifications',
    'disable' => 'Désactiver sur cet appareil',

    'denied_help' => 'Votre navigateur a refusé les notifications pour BouclePro. Pour les réactiver, ouvrez les réglages du site dans votre navigateur et autorisez les notifications, puis revenez sur cette page.',
    'unsupported_help' => 'Ce navigateur ne propose pas les notifications web. Sur iPhone, installez d’abord BouclePro sur votre écran d’accueil : les notifications ne sont disponibles que depuis l’application installée.',

    'devices_count' => '{0}Aucun appareil enregistré|{1}1 appareil enregistré|[2,*]:count appareils enregistrés',

    // On ne promet pas ce qui n'est pas livré (CDC 3.2).
    'not_yet_sending' => 'Votre appareil est enregistré. L’envoi des notifications sera activé prochainement : vous ne recevrez encore rien pour le moment.',

    'enable_failed' => 'L’activation n’a pas abouti. Réessayez dans un instant.',
    'disable_failed' => 'La désactivation n’a pas abouti. Réessayez dans un instant.',
    'disabled' => 'Les notifications Push ne sont pas disponibles pour le moment.',
    'key_stale' => 'Cet appareil a été abonné avec une clé de sécurité qui n’a plus cours : il ne peut plus recevoir de notification. Cliquez sur « Activer » pour le réabonner.',
];
