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
    'preferences_title' => 'Réglages des notifications',
    'preferences_subtitle' => 'Choisissez ce que BouclePro vous envoie.',
    'preferences_save' => 'Enregistrer',
    'preferences_saved' => 'Vos réglages ont été enregistrés.',
    'preferences_mandatory' => 'Toujours active',
    'preferences_mandatory_hint' => 'Cette notification vous est adressée personnellement et appelle une réponse : elle ne peut pas être désactivée.',
    'preferences_link' => 'Réglages',
    'preferences_back' => 'Mes notifications',
    'channel_in_app' => "Dans l'application",
    'channel_email' => 'Par email',

    'keys' => [
        'loop_invitation' => 'Invitation à rejoindre une Boucle',
    ],

    // Repli quand une cle du catalogue n'a pas encore de libelle. Il ne doit
    // jamais afficher la cle technique : elle ne veut rien dire pour un membre.
    'key_fallback' => 'Notification',

    /*
    |--------------------------------------------------------------------------
    | Emails de notification (TASK-1378)
    |--------------------------------------------------------------------------
    |
    | Le sous-tableau est nomme d'apres la cle du catalogue, POINTS REMPLACES :
    | `loop.invitation` devient `loop_invitation`. Un point y serait lu comme
    | une hierarchie de groupes par le resolveur de traductions, et la cle
    | deviendrait introuvable pour une raison sans rapport avec son contenu.
    |
    | `:url` est rendu DEUX FOIS par le livreur : avec la vraie adresse pour
    | l'envoi, avec un marqueur expurge pour l'archivage. Ne pas construire de
    | lien en dur ici : ce sont ces deux rendus qui protegent le jeton.
    |
    */
    'email' => [
        'loop_invitation' => [
            'subject' => 'Vous êtes invité à rejoindre une boucle',
            'body' => '<p>Bonjour,</p>'
                .'<p>Vous êtes invité à rejoindre une boucle sur BouclePro.</p>'
                .'<p><a href=":url">Voir l\'invitation</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L\'équipe BouclePro</p>',
        ],
    ],
];
