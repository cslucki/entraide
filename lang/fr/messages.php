<?php

return [
    'title' => 'Messages',

    // TASK-1703 — le sous-titre de la barre haute de la messagerie.
    'conversation_count' => '{0}Aucune conversation|{1}1 conversation|[2,*]:count conversations',
    'no_conversations' => 'Aucune conversation',
    'back_to_conversations' => 'Retour aux conversations',
    'select_conversation' => 'Sélectionnez une conversation',
    'direct_conversation' => 'Discussion',
    'direct_conversation_started' => 'Discussion démarrée.',
    'exchange_subject' => 'Échange #:id',

    // TASK-1754 §3.2 — arbitrage Q2 de Cyril (05/11) : le BADGE reste court et
    // NEUTRE, c'est la PHRASE qui porte le sens (qui aide qui). Deux fois moins
    // de libellés à maintenir, et c'est la phrase qui supprime la confusion
    // constatée en recette — un badge, aussi bien écrit soit-il, ne dira jamais
    // « Ottoline dit avoir reçu l'aide, confirmez pour recevoir vos 30 min ».
    'status' => [
        'pending' => 'Proposition',
        'accepted' => 'Entraide convenue',
        'buyer_done' => 'À confirmer',
        'completed' => 'Terminé',
        'refused' => 'Déclinée',
        'cancelled' => 'Annulé',
    ],

    // Les phrases orientées rôle. `:temps` reçoit le double affichage
    // (« 30 min · 30 pts »), jamais un nombre de points nu.
    'phrase' => [
        'pending_decideur' => ':prenom répond à votre annonce « :titre » — :temps',
        'pending_proposant' => 'Votre proposition est partie. :prenom doit répondre.',
        'accepted' => 'Vous avez convenu de cet échange — :temps',
        'buyer_done_aidant' => ':prenom dit avoir reçu l\'aide. Confirmez pour recevoir vos :temps.',
        'buyer_done_aide' => 'Vous avez dit avoir reçu l\'aide. :prenom doit confirmer.',
        'completed' => 'Échange terminé — :temps transférés. Merci !',
        'refused_proposant' => ':prenom a décliné votre proposition.',
        'refused_decideur' => 'Vous avez décliné cette proposition.',
        'cancelled' => 'Cet échange a été annulé.',
    ],

    // TASK-1754 §3.3 — des verbes d'entraide. `cancel` se dédouble : retirer SA
    // proposition n'est pas annuler un échange déjà convenu, et les confondre
    // sous « Annuler » cachait ce qui se passe vraiment.
    'actions' => [
        'accept' => 'Accepter l\'échange',
        'refuse' => 'Décliner',
        'adjust' => 'Proposer un autre temps',
        'cancel' => 'Annuler',
        'cancel_withdraw' => 'Retirer ma proposition',
        'cancel_exchange' => 'Annuler l\'échange',
        'mark_done' => 'J\'ai bien reçu l\'aide',
        'confirm' => 'Confirmer — recevoir mes :temps',
        // « Contester » était un mot de litige pour dire « l'aide n'est pas
        // encore finie ». Même route `contest()`, geste dédramatisé.
        'contest' => 'Pas encore terminé',
    ],

    // TASK-1754 §9.4 — le MOT devient le geste principal, la note passe au
    // second plan. Arbitrage Q6 de Cyril : la note reste OBLIGATOIRE (la colonne
    // `reviews.rating` est NOT NULL et nourrit la moyenne du profil ainsi que
    // les badges) — elle est donc discrète, pas optionnelle, et le libellé le
    // dit honnêtement.
    'review_exchange' => 'Dites merci à :prenom',
    'review_intro' => 'L\'entraide est terminée — :temps partagées. Votre mot de remerciement sera affiché sur son profil.',
    'review_placeholder' => 'Un mot de remerciement (affiché sur son profil)…',
    'review_rating_hint' => 'Vous pouvez ajuster la note.',
    'send_review' => 'Envoyer le merci',
    'composer_placeholder' => 'Votre message...',
    'conversation_closed' => 'Cette conversation est terminée.',
    'empty_thread' => 'Aucun message pour le moment',
    'empty_thread_hint' => 'Soyez le premier à écrire !',
    'write_message' => 'Écrivez un message...',
    'reply_to' => 'Réponse à',
    'image_preview' => 'Aperçu',
    'image_alt' => 'Image',
    'reply' => 'Répondre',
    'pin' => 'Épingler',
    'unpin' => 'Désépingler',
    'edit' => 'Modifier',
    'save' => 'Enregistrer',
    'cancel_edit' => 'Annuler',
    'delete' => 'Supprimer',
    'delete_confirm' => 'Supprimer ce message ?',
    'delete_modal_title' => 'Supprimer ce message ?',
    'delete_modal_body' => 'Le message sera masqué dans la boucle et remplacé par un indicateur de suppression.',
    'copy' => 'Copier',
    'copied' => 'Copié',
    'copy_failed' => 'Copie impossible',
    'edited' => 'modifié',
    'deleted_message_placeholder' => 'Ce message a été supprimé.',
    'load_previous_messages' => 'Charger les messages précédents',
    'edit_failed' => 'Impossible de modifier ce message.',
    'react_to_message' => 'Réagir au message',
    'react_with' => 'Réagir avec :type',
    'more_reactions' => 'Afficher plus de réactions',
    'pinned_message' => 'Message épinglé',
    'view' => 'Voir',
    'me' => 'Moi',
    'member' => 'Membre',
    'body_or_image_required' => 'Ajoutez un message ou une image.',
    'invalid_file' => 'Seules les images (JPG, PNG, GIF, WebP) sont acceptées.',
    'cross_org' => 'Vous ne pouvez pas démarrer une conversation en dehors de votre organisation.',
    // TASK-1693 bug AC
    'send_image' => 'Envoyer une image',
    // TASK-1704 â la Messagerie accepte desormais des fichiers. La ChatLoop,
    // elle, n'accepte toujours que des images : les deux intitules coexistent
    // parce que les deux surfaces ne promettent pas la meme chose.
    'send_file' => 'Envoyer une image ou un fichier',
    'invalid_attachment' => 'Ce type de fichier n’est pas accepté, ou il dépasse la taille maximale.',
    'attachment_fallback_name' => 'Pièce jointe',
    'attachment_open' => 'Ouvrir',
    'attachment_download' => 'Télécharger',
    'take_photo' => 'Prendre une photo',
];
