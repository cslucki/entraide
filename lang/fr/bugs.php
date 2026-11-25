<?php

// TASK-1904 — « Signalement » : point d'entree unique et CONFIDENTIEL.
// Fin de la transparence publique de TASK-1493 (decision MASTER D6).
return [
    'title' => 'Signalement',
    'intro' => 'Signalez un problème technique, un comportement, un contenu ou une situation qui vous inquiète. Votre signalement est confidentiel : seules les personnes chargées de la modération le lisent.',
    'category_label' => 'Catégorie',
    'category_placeholder' => 'Choisissez une catégorie…',
    'categories' => [
        'technical_bug' => 'Bug technique',
        'inappropriate_behavior' => 'Comportement déplacé',
        'harassment_threats' => 'Harcèlement ou menaces',
        'inappropriate_content' => 'Contenu inapproprié',
        'child_safety' => 'Sécurité des enfants',
        'spam_fraud' => 'Spam ou fraude',
        'other' => 'Autre',
    ],
    'details_label' => 'Description',
    'details_placeholder' => 'Décrivez ce qui s’est passé, où et quand. Indiquez le nom de la personne ou le lien du contenu concerné si possible.',
    'page_concerned' => 'Page concernée : :url',
    'confidential_note' => 'Personne d’autre que l’équipe de modération ne voit votre signalement. Les signalements « Sécurité des enfants » sont traités en priorité.',
    'child_safety_link' => 'Normes de sécurité des enfants',
    'submit' => 'Envoyer le signalement',
    'sent' => 'Signalement envoyé. Merci, il sera examiné.',
    'no_organization' => 'Impossible de rattacher ce signalement à une organisation.',
    'mine_title' => 'Mes signalements',
    'empty' => 'Vous n’avez envoyé aucun signalement.',
    'guest_text' => 'Connectez-vous pour envoyer un signalement. Les signalements sont confidentiels et ne sont jamais affichés publiquement.',
    'guest_child_safety' => 'Sans compte, pour un problème de sécurité des enfants, consultez :',
    'status_pending' => 'En attente',
    'status_reported' => 'Signalé',
    'status_fixed' => 'Corrigé',
    'status_dismissed' => 'Classé',
    'status_resolved' => 'Traité',
    'resolution' => 'Réponse :',
    'reported_on' => 'Envoyé le :date',
    'fixed_on' => 'traité le :date',
];
