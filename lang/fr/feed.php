<?php

return [
    // TASK-1752 — les evenements automatiques.
    //
    // Aucun libelle ne dit « vient de » : la meme ligne relue une semaine plus
    // tard serait fausse, et elle porte deja sa date juste a cote. Le temps du
    // verbe est un choix gratuit puisque le libelle est CALCULE a l'affichage
    // — c'est aussi ce qui permet la reprise d'historique antidatee.
    'events' => [
        'badge' => 'Activite',
        'presentation_completed' => ':name a fini sa présentation',
        'help_request_published' => ':name a publié une demande d\'aide',
        'service_published' => ':name a publié une proposition d\'aide',
        'loop_joined' => ':name a rejoint la Boucle :object',
        'loop_created' => ':name a créé la Boucle :object',
        'loop_event_created' => ':name a créé l\'évènement :object',
        'loop_watch_item_created' => ':name a partagé une veille dans la Boucle :object',
        'loop_poll_created' => ':name a posé un sondage dans la Boucle :object',
        'loop_article_published' => ':name a publié un article dans la Boucle :object',
        'loop_dossier_files_uploaded' => ':name a déposé des fichiers dans la Boucle :object',
        'loop_roadmap_item_created' => ':name a ajouté une action à la Roadmap de la Boucle :object',
        'loop_roadmap_item_moved' => ':name a fait avancer une action de la Roadmap de la Boucle :object',
        'files_count' => '1 fichier|:count fichiers',
        // TASK-1809 (decision Cyril, 12/11) — REMPLACE l'arbitrage du 05/11
        // (« seul l'AIDANT est nomme, jamais Barnabe ») : « Si on nomme les
        // deux, c'est une plateforme de confiance et ouverte. Et il faut
        // etre connecte et membre accepte. » Les deux personnes sont donc
        // nommees, et l'annonce par son TITRE (:object) — BouclePro est une
        // plateforme de pedagogie par entraide, pas de troc.
        'help_completed' => ':name a aidé :beneficiaire avec « :object » (:temps)',
        'help_category_fallback' => 'Entraide',
        'object_gone' => 'un contenu supprimé',
    ],

    'events_sans_boucle' => [
        'loop_watch_item_created' => ':name a partagé une veille',
        'loop_poll_created' => ':name a posé un sondage',
        'loop_article_published' => ':name a publié un article',
        'loop_dossier_files_uploaded' => ':name a déposé des fichiers',
        'loop_roadmap_item_created' => ':name a ajouté une action à la Roadmap',
        'loop_roadmap_item_moved' => ':name a fait avancer une action de la Roadmap',
    ],

    'organization' => 'Organisation',
    'title' => 'Flux',
    'subtitle' => 'Annonces utiles, décisions et informations à retenir.',
    'dashboard' => 'Mon tableau de bord',
    'my_posts' => 'Mes annonces',
    'new_post' => 'Nouvelle annonce',
    'pinned' => 'Épinglées',
    'empty' => 'Aucune annonce pour le moment.',
    'create_first' => 'Créer la première annonce',
    'load_more' => 'Voir plus',
    'post_badge' => 'Annonce',
    'pinned_badge' => 'Épinglée',
    'loop_count' => ':count boucle|:count boucles',
    'member_fallback' => 'Membre',
    'shared_link' => 'Lien partagé',
    'open_loop' => 'Ouvrir la Boucle',
    'comment_cta' => 'Commentez',
    'view_comments' => 'Voir les :count commentaires',
    'comment_placeholder' => 'Ajouter un commentaire utile',
    'comment_submit' => 'Commenter',
    'react' => 'Réagir :type',
    'comments_title' => 'Commentaires (:count)',
];
