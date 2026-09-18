<?php

return [
    'notification' => [
        'created' => 'Demande publiée avec succès.',
        'created_and_relayed' => 'Demande publiée et annoncée dans la Boucle.',
        'updated' => 'Demande mise à jour.',
        'closed' => 'Demande fermée.',
    ],

    'relay_loop_label' => 'Relais dans une Boucle',
    'relay_loop_none' => 'Ne pas relayer cette demande',
    'relay_loop_help' => 'L’annonce ne sera créée qu’après la publication de la demande.',
    'relay_loop_invalid' => 'Cette Boucle n’est pas une destination de relais autorisée.',
    'relay_failed' => 'La demande a bien été créée, mais son annonce dans la Boucle n’a pas pu être publiée.',
    'chat_projection_body' => 'Nouvelle demande d’aide : :title',
    'view_request' => 'Voir la demande',
    'projection_unavailable' => 'Cette demande n’est plus accessible.',
    'status_closed' => 'Demande fermée',

    'show' => [
        'back' => 'Retour à l\'explorateur',
        'edit' => 'Modifier',
        'points' => 'points',
        'attachments' => 'Pièces jointes',
        'report_button' => 'Signaler cette demande',
        'report_placeholder' => 'Motif du signalement...',
        'report_inappropriate' => 'Contenu inapproprié',
        'report_scam' => 'Arnaque ou fraude',
        'report_spam' => 'Spam',
        'report_other' => 'Autre',
        'report_details' => 'Détails (optionnel)...',
        'report_submit' => 'Envoyer le signalement',
        'propose_help' => 'Proposer mon aide',
        'your_balance' => 'Votre solde : :points pts',
        'before' => 'Avant le :date',
    ],

    'edit' => [
        'heading' => 'Demande d\'aide',
        'description' => 'Description *',
        'category' => 'Catégorie *',
        'delivery_mode' => 'Mode de prestation *',
        'remote' => '🌐 À distance',
        'onsite' => '📍 Sur site',
        'both' => '🌐📍 Les deux',
        'budget_min' => 'Budget min *',
        'budget_max' => 'Budget max',
        'optional' => '(optionnel)',
        'attachments' => 'Pièces jointes',
        'attachments_add' => 'Ajouter des fichiers',
        'attachments_types' => 'JPG, PNG, PDF, DOC, XLS — max 10 Mo',
        'deadline' => 'Délai',
        'save' => 'Enregistrer',
        'cancel' => 'Annuler',
    ],

    // TASK-1553 — W2-2 : la carte pre-envoi.
    //
    // Elle ne promet RIEN. Le brouillon est un relais ephemere (15 min), deja
    // consomme quand cette page s'affiche : aucune sauvegarde n'est annoncee,
    // aucune restauration n'est suggeree. Ce qu'elle dit est ce qui est vrai
    // maintenant, a l'ecran.
    'presend_title' => 'Avant de publier',
    'presend_destination' => 'Sera publiée dans :',
    'presend_members' => '{0} aucun membre|{1} 1 membre|[2,*] :count membres',
    // L'ORIGINE du brouillon : de quel geste il vient. Sans elle, la carte
    // dirait « voici les fondements » sans dire de quoi.
    'presend_origin_shell' => 'Préparée depuis votre conversation avec BouclePro IA.',
    'presend_origin_loop_clarification' => 'Préparée depuis « Qui peut m\'aider ? » dans cette Boucle.',
    'presend_origin_loop_clarification_unavailable' => 'Préparée à partir de vos mots, sans intervention de l\'IA.',
    'presend_note' => 'Rien n\'est publié tant que vous ne l\'avez pas confirmé. Vous pouvez tout modifier ci-dessous.',
];
