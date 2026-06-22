<?php

return [
    'title' => 'Annuaire des membres',
    'member_count' => ':count membre inscrit|:count membres inscrits',
    'active_services' => ':label actifs',
    'open_requests' => 'Demandes ouvertes',
    'request_count' => ':count demande|:count demandes',
    'setup_title' => 'Base de données à initialiser',
    'setup_body' => 'L\'annuaire des membres n\'est pas encore disponible car la base de données locale ne contient pas d\'organisation configurée.',
    'setup_intro' => 'Pour initialiser l\'environnement local :',
    'setup_steps' => [
        'Synchroniser la base de production via',
        'Exécuter les migrations locales :',
        'Injecter les comptes QA :',
        'Vider le cache :',
    ],
];
