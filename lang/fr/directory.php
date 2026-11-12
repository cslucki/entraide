<?php

return [
    'title' => 'Annuaire des membres',
    'member_count' => ':count membre inscrit|:count membres inscrits',
    'active_services' => ':label actifs',
    'open_requests' => 'Demandes ouvertes',
    'request_label' => 'demande|demandes',
    'setup_title' => 'Base de données à initialiser',
    'setup_body' => 'L\'annuaire des membres n\'est pas encore disponible car la base de données locale ne contient pas d\'organisation configurée.',
    'setup_intro' => 'Pour initialiser l\'environnement local :',
    'setup_steps' => [
        'Synchroniser la base de production via',
        'Exécuter les migrations locales :',
        'Injecter les comptes QA :',
        'Vider le cache :',
    ],
    // TASK-1693 bug AA
    'write_to' => 'Ecrire a :name',
    // TASK-1697 correctif 15
    'service_label' => 'proposition|propositions',
    'active_services_title' => 'Propositions actives',
    'article_label' => 'article|articles',
    'articles_title' => 'Articles publiés',
    'loops_title' => 'Boucles',
    'search_placeholder' => 'Rechercher un nom, un mot-clé…',

    // TASK-1762 point 10 — le filtre par ville.
    'city_label' => 'Localisation',
    // TASK-1762 point 12 — « Localisation » et non « Toutes les localisations » :
    // c'est l'option par defaut d'un menu, pas une phrase, et elle en fixait la largeur.
    'city_all' => 'Localisation',

    // TASK-1762 point 11.4 — la vue « Nouveaux arrivés ».
    'latest_button' => 'Nouveaux arrivés',
    // TASK-1812 correctif 04 — la grille par defaut est passee a l'ordre
    // alphabetique de prenom ; ce bouton remplace « Nouveaux arrivés » une
    // fois cette vue active, pour y revenir.
    'alphabetical_button' => 'Ordre alphabétique',

    // TASK-1762 point 14 — le pli des Boucles au-dela de trois.
    'loops_more' => ':count autre Boucle|:count autres Boucles',
    'loops_reduce' => 'réduire',
    'joined_ago' => 'A rejoint :when',

    // TASK-1762 point 11.2 — le bloc qui charge la suite.
    'load_more' => 'Voir le membre suivant|Voir les :count membres suivants',

    // TASK-1762 point 9 — employé quand l'Organization a ouvert le champ Structure.
    'search_placeholder_with_structure' => 'Rechercher un nom, une structure, un mot-clé…',
    'search_label' => 'Rechercher un membre',
    'search_submit' => 'Rechercher',
    'search_clear' => 'Effacer',
    'search_no_result' => 'Aucun membre ne correspond à cette recherche.',
    'website_title' => 'Site web',
    'linkedin_title' => 'LinkedIn',
];
