<?php

// TASK-1435 — SW-5 : libelles du contexte public du Shell Welcome.
return [
    // TASK-1437 — SW-7 : repli local (jamais un second appel) et instruction de langue.
    'fallback_unavailable' => 'La réponse est temporairement indisponible. Vous pouvez réessayer dans un instant.',
    'fallback_empty' => 'Je n\'ai pas de réponse à proposer pour l\'instant. Pouvez-vous reformuler ?',
    'instruction_locale' => 'Réponds dans la langue : :locale.',
    'context' => [
        'identity' => ':name — présentation publique',
        'platform_constitution' => 'Constitution IA de la plateforme (Mycélium)',
        'organization_constitution' => 'Constitution IA de :name (publiée)',
        'name' => 'Nom',
        'tagline' => 'Devise',
        'headline' => 'Accroche',
        'pitch' => 'Promesse',
        'description' => 'Présentation',
    ],
];
