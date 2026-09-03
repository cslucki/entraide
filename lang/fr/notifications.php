<?php

return [
    // Le Centre
    'title' => 'Mes notifications',
    'subtitle' => 'Ce que BouclePro a voulu vous signaler.',

    // Les filtres
    'filter_all' => 'Toutes',
    'filter_unread' => 'Non lues',

    // Les actions
    'mark_read' => 'Marquer comme lu',
    'mark_all_read' => 'Tout marquer comme lu',

    // Les etats vides — DEUX, et c'est voulu. Une page vide et un filtre vide
    // ne disent pas la meme chose : la premiere dit « rien ne vous attend »,
    // la seconde « rien ICI, mais ailleurs oui ». Les confondre laisserait
    // croire a une boite vide alors qu'elle ne l'est pas.
    'empty_title' => 'Aucune notification',
    'empty_body' => 'Vous serez prévenu ici quand quelque chose vous concernera.',
    'filter_empty_title' => 'Tout est lu',
    'filter_empty_body' => 'Aucune notification non lue pour le moment.',

    // L'etat de lecture
    'target_unreachable' => "Cet élément n'est plus accessible.",
    'open' => 'Ouvrir',
    'unread_badge' => 'Non lue',

    // Les libelles par cle du catalogue. Les points de la cle deviennent des
    // tirets bas : `loop.invitation` -> `keys.loop_invitation`.
    'keys' => [
        'loop_invitation' => 'Invitation à rejoindre une Boucle',
    ],

    // Repli quand une cle du catalogue n'a pas encore de libelle. Il ne doit
    // jamais afficher la cle technique : elle ne veut rien dire pour un membre.
    'key_fallback' => 'Notification',
];
