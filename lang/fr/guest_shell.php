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
        // TASK-1461 — Growth V3 §17 : ce qui est réellement possible ici, maintenant.
        'workshops_runtime' => 'Ateliers ouverts en ce moment',
        'workshops_intro' => 'Ateliers publiés avec une prochaine session (ne proposer que ceux-ci, avec leur lien) :',
        'workshop_line' => '- :title:promise — :when — :format — :url',
        'platform_constitution' => 'Constitution IA de la plateforme (Mycélium)',
        'organization_constitution' => 'Constitution IA de :name (publiée)',
        'name' => 'Nom',
        'tagline' => 'Devise',
        'headline' => 'Accroche',
        'pitch' => 'Promesse',
        'description' => 'Présentation',
    ],
    // TASK-1440 — Guest PageContext V1 : le bloc « où suis-je ? ».
    // TASK-1442 — SW-8a : l'overlay public (textes visibles par le visiteur).
    'ui' => [
        'open' => 'Une question ?',
        'title' => 'Assistant de :name',
        'close' => 'Fermer',
        'welcome' => 'Bonjour ! Je suis l\'assistant public de :name. Posez votre question : je réponds à partir des informations publiques, sans créer de compte.',
        'placeholder' => 'Votre question…',
        'send' => 'Envoyer',
        'sending' => 'Réponse en cours…',
        'privacy' => 'Échange public sans compte. Ne partagez pas de données personnelles.',
        // TASK-1467 (CDC 21h-23h §2.5) : l'etat degrade a TOUJOURS une cause
        // technique — `displayPayload()` ne le pose que si l'Organization a
        // active le Shell et que la politique n'est pas prete. Proposer un
        // compte comme remede serait donc un mensonge : creer un compte ne
        // pose pas un plafond plateforme et ne configure pas une cle. On dit
        // ce qui est vrai, et on rend la page — qui, elle, reste entiere.
        'degraded_text' => 'L\'assistant de :name est momentanément indisponible. Vous pouvez continuer à explorer :name et ses ateliers.',
        'network_error' => 'La connexion a échoué. Réessayez dans un instant.',
        'failed' => 'La réponse n\'a pas pu être produite. Vous pouvez réessayer.',
        'refused_generic' => 'Votre message n\'a pas pu être traité pour le moment.',
        'refused_max_messages_reached' => 'Cette conversation a atteint sa limite de messages. Créez un compte pour continuer.',
        'refused_visitor_monthly_quota_reached' => 'Vous avez atteint le nombre de messages possibles ce mois-ci. Créez un compte pour continuer.',
        'refused_rate_limited' => 'Un instant : trop de messages en peu de temps.',
        'refused_input_out_of_bounds' => 'Votre message est vide ou trop long.',
        'remaining' => ':count message(s) restant(s)',
        'limit_reached' => 'Limite de la conversation atteinte.',
        'first_after' => 'Voir la page de :name ↓',
    ],
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
