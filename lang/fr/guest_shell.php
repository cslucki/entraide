<?php

// TASK-1435 — SW-5 : libelles du contexte public du Shell Welcome.
return [
    // TASK-1437 — SW-7 : repli local (jamais un second appel) et instruction de langue.
    'fallback_unavailable' => 'La réponse est temporairement indisponible. Vous pouvez réessayer dans un instant.',
    'fallback_empty' => 'Je n\'ai pas de réponse à proposer pour l\'instant. Pouvez-vous reformuler ?',
    'instruction_locale' => 'Réponds dans la langue : :locale.',
    'context' => [
        'identity' => ':name — présentation publique',
        'usage_reference' => 'À quoi sert cet espace — :title',
        'page' => 'Où se trouve le visiteur',
        'platform_constitution' => 'Constitution IA de la plateforme (Mycélium)',
        'organization_constitution' => 'Constitution IA de :name (publiée)',
        'name' => 'Nom',
        'tagline' => 'Devise',
        'headline' => 'Accroche',
        'pitch' => 'Promesse',
        'description' => 'Présentation',
    ],
    // TASK-1440 — Guest PageContext V1 : le bloc « où suis-je ? ».
    'page' => [
        'kind' => 'Surface',
        'label' => 'Page',
        'route' => 'Route',
        'public_id' => 'Identifiant public',
        'next_step' => 'Prochaine étape possible',
        'kind_organization_home' => 'Accueil public de l\'organisation',
        'kind_signup' => 'Page d\'inscription de l\'organisation',
        'kind_workshop_page' => 'Page d\'atelier',
        'kind_workshop_session' => 'Session d\'atelier',
        'cta_signup' => 'Créer un compte',
        'signup_label' => 'Inscription à :name',
    ],
];
