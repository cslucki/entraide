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
        // TASK-1702
        'blog_co_author_added' => 'On vous a ajouté comme co-auteur d’un article',

        // TASK-1697
        'blog_comment_created' => 'Un nouveau commentaire sur votre article',
        'loop_invitation' => 'Invitation à rejoindre une Boucle',
        'loop_member_added' => 'Ajout à une Boucle',

        // TASK-1381 — deux libellés, parce que ce sont deux faits. Le membre
        // doit savoir ce qui lui est arrivé sans avoir à ouvrir quoi que ce soit.
        'loop_join_request_accepted' => 'Votre demande d’adhésion a été acceptée',
        'loop_join_request_rejected' => 'Votre demande d’adhésion n’a pas été retenue',
    ],

    /*
     * TASK-1725 (demande Cyril, 29/10) — les MEMES faits, en nommant la Boucle.
     *
     * « Ajout a une Boucle » ne disait pas LAQUELLE : un membre de plusieurs
     * Boucles devait ouvrir pour savoir de quoi on lui parlait, ce que le
     * libelle existe precisement pour lui epargner.
     *
     * Un groupe SEPARE, et non un `:loop` ajoute au groupe `keys` : le nom
     * n'est pas toujours connu au rendu — Boucle supprimee, ou d'un autre
     * tenant — et une phrase a trou qui recevrait une chaine vide donnerait
     * « Ajout a la Boucle «  » ». Les deux formes coexistent donc, et la vue
     * choisit selon ce qu'elle a. `keys` reste la forme sans objet.
     *
     * Les cles ici sont un SOUS-ENSEMBLE de `keys` : seules les notifications
     * qui portent une Boucle en ont une.
     */
    'keys_named' => [
        'loop_invitation' => 'Invitation à rejoindre la Boucle « :loop »',
        'loop_member_added' => 'Ajout à la Boucle « :loop »',
        'loop_join_request_accepted' => 'Votre demande d’adhésion à « :loop » a été acceptée',
        'loop_join_request_rejected' => 'Votre demande d’adhésion à « :loop » n’a pas été retenue',
    ],

    // TASK-1725 — « Ouvrir » ne disait pas quoi. Le libelle depend donc de
    // l'objet : une notification de Boucle mene a une Boucle, et le dire evite
    // au membre de deviner ou le bouton l'emmene.
    'open_loop' => 'Ouvrir la Boucle',

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
        'loop_member_added' => [
            'subject' => 'Vous avez été ajouté à une Boucle',
            'body' => '<p>Bonjour,</p>'
                .'<p>Vous avez été ajouté à une Boucle sur BouclePro. Vous en êtes désormais membre.</p>'
                .'<p><a href=":url">Ouvrir la Boucle</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L\'équipe BouclePro</p>',
        ],
        // TASK-1725 — dernier recours pour la demande d'adhesion ACCEPTEE.
        //
        // Trois couches, de la plus specifique a la plus generale :
        // `SystemEmailTemplate` (autorite administrable, par Organization et
        // locale), puis la vue `emails.loop-join-request-accepted` quand le
        // gabarit manque ou echoue, puis CES clefs quand le livreur ne sait
        // meme plus de quel objet il parle. Volontairement neutre : elle ne
        // nomme ni la Boucle ni le decideur, parce qu'a ce niveau on ne les a
        // plus. Le contrat de coherence TASK-1379 l'exige pour toute cle
        // portant le canal EMAIL.
        'loop_join_request_accepted' => [
            'subject' => 'Votre demande pour rejoindre une Boucle a été acceptée',
            'body' => '<p>Bonjour,</p>'
                .'<p>Votre demande pour rejoindre une Boucle sur BouclePro a été acceptée. Vous en êtes désormais membre.</p>'
                .'<p><a href=":url">Ouvrir la Boucle</a></p>'
                .'<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>:url</p>'
                .'<p>À bientôt,<br>L\'équipe BouclePro</p>',
        ],
    ],
];
