<?php

/**
 * TASK-1608 — les libelles de la carte interactive.
 *
 * Les NOMS et DESCRIPTIONS des Boucles ne sont pas ici : ils viennent du
 * contenu ecrit par les membres, et ne sont jamais traduits automatiquement
 * (§13 du mandat).
 */
return [
    'title' => 'Logigramme',
    'subtitle' => 'Comment fonctionne BouclePro, et ce qui existe réellement dans cette Organization.',

    // Premier niveau (§4)
    'root' => 'Que voulez-vous faire ?',

    'intent_need_help' => "J'ai besoin d'aide",
    'intent_need_help_hint' => "Exprimez ce qui vous bloque. BouclePro vous aide à le formuler, puis à trouver qui peut vous répondre.",
    'intent_offer_help' => 'Je peux aider',
    'intent_offer_help_hint' => 'Décrivez ce que vous savez faire. Vos compétences deviennent visibles là où elles sont utiles.',
    'intent_explore_idea' => 'Explorer une idée',
    'intent_explore_idea_hint' => "Une intuition, une fascination, un sujet à creuser : cette Organization et ses documents deviennent votre matière.",
    'intent_connect' => 'Créer du lien',
    'intent_connect_hint' => 'Ces personnes devraient se rencontrer. Provoquez la rencontre plutôt que de l\'attendre.',

    // Le mécanisme (§5)
    'step_clarify' => 'Clarification',
    'step_clarify_hint' => "Une intention floue devient une demande claire. C'est la première chose que BouclePro fait, quelle que soit la porte d'entrée.",
    'step_match' => 'Personnes et Boucles pertinentes',
    'step_match_hint' => 'La demande rencontre celles et ceux qui peuvent y répondre, dans les Boucles où le sujet vit déjà.',
    'step_exchange' => 'Échanges',
    'step_exchange_hint' => 'La conversation se tient dans la Boucle, et y reste : elle devient la mémoire du groupe.',
    'step_ai' => "IA d'assistance",
    'step_ai_hint' => "L'IA aide à formuler, à retrouver et à résumer. Elle ne décide jamais à votre place.",
    'step_resources' => 'Ressources et cartes',
    'step_resources_hint' => 'Les outils de la Boucle : cartes, suivis, agenda, ce que le groupe a choisi d\'activer.',
    'step_dossiers' => 'Dossiers et documents',
    'step_dossiers_hint' => 'Les documents partagés deviennent une base consultable, bornée à ce que vous avez le droit de lire.',
    'step_synthesis' => 'Synthèse sourcée',
    'step_synthesis_hint' => 'Une réponse qui cite ses sources. Sans source, BouclePro le dit plutôt que d\'inventer.',
    'step_decision' => 'Décision',
    'step_decision_hint' => 'Le groupe tranche, et la trace de ce qui a été décidé reste attachée à la Boucle.',

    // Ce à quoi cela aboutit (§5)
    'outcome_entraide' => 'Entraide',
    'outcome_relation' => 'Mise en relation',
    'outcome_learning' => 'Apprentissage',
    'outcome_coordination' => 'Coordination',
    'outcome_action' => 'Action',
    'outcome_memory' => 'Mémoire partagée',

    // La branche des Boucles réelles (§11 — aucune recommandation)
    'explore_loops' => 'Explorer les Boucles de cette Organization',
    // Le libelle du NOEUD : une card n'a pas la place d'une phrase. Le texte
    // long reste celui des sections en cartes, ou il a la place de se dire.
    'explore_loops_node' => 'Explorer les Boucles',
    'explore_loops_empty' => 'Aucune Boucle à explorer ici pour le moment.',
    'guest_hint' => 'Connectez-vous pour explorer les Boucles de cette Organization.',

    // États d'accès — mêmes quatre états que le catalogue et le Shell
    'access_open' => 'Entrée libre',
    'access_request' => 'Sur demande',
    'access_pending' => 'Demande en attente',
    'access_member' => 'Vous en êtes membre',

    // Actions
    'cta_view' => 'Voir la Boucle',
    'cta_open' => 'Ouvrir la Boucle',
    'cta_request' => 'Demander à rejoindre',
    'back' => 'Revenir',
    'overview' => "Vue d'ensemble",
    'reset' => 'Réinitialiser',

    // Accessibilité / repli (§15)
    'fallback_title' => 'Le parcours, en texte',
    'fallback_intro' => "La même carte, lisible sans le graphe interactif.",
    'fallback_loops' => 'Boucles accessibles depuis cette page',
    'graph_label' => 'Logigramme interactif de cette Organization',

    // TASK-1608 §2 correctifs — la PREMIERE ETAPE propre a chaque intention.
    // Entrees differentes, moteur commun : sans elles, les quatre portes
    // revelaient exactement les memes noeuds.
    'entry_need_help' => 'Clarifier mon besoin',
    'entry_need_help_hint' => "Dire ce qui bloque, avec vos mots. BouclePro reformule avec vous jusqu'à ce que la demande soit claire pour quelqu'un d'autre.",
    'entry_offer_help' => 'Décrire ce que je peux apporter',
    'entry_offer_help_hint' => 'Nommer une compétence, un temps disponible, un savoir-faire. Ce que vous offrez devient visible là où il sert.',
    'entry_explore_idea' => 'Formuler le sujet à explorer',
    'entry_explore_idea_hint' => "Poser la question, même mal dégrossie. Une intuition se travaille ; elle n'a pas besoin d'être déjà une thèse.",
    'entry_connect' => 'Identifier les personnes et la raison',
    'entry_connect_hint' => 'Dire qui devrait se rencontrer, et pourquoi maintenant. La raison compte autant que les noms.',

    // Les deux replis de la vue d'ensemble.
    'aggregate_engine' => 'IA, ressources et documents',
    'aggregate_engine_hint' => "L'assistance, les outils de la Boucle et les Dossiers partagés : ce que BouclePro mobilise pour répondre.",
    'aggregate_decide' => 'Synthèse et décision',
    'aggregate_decide_hint' => 'Une réponse qui cite ses sources, puis un choix que le groupe assume et dont la trace reste.',

    // Barre d'outils du graphe (§8 correctifs).
    'zoom_in' => 'Agrandir',
    'zoom_out' => 'Réduire',
    'recenter' => 'Recentrer sur la sélection',
    'fit' => 'Ajuster à la vue',
    'continue' => 'Continuer',

    // Parcours en cartes (§11 correctifs).
    'cards_intents_title' => 'Que voulez-vous faire ?',
    'cards_engine_title' => 'Comment BouclePro vous accompagne',
    'cards_outcomes_title' => 'Ce que cela produit',
    'cards_loops_title' => 'Les Boucles que vous pouvez explorer',
];
