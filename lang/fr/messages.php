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
    // TASK-1806 — « Échange » etait le dernier reste du lexique marchand dans
    // le TITRE d'une conversation, et il remontait jusque dans l'objet des
    // courriels (`TransactionProposalMailer::titreDeLAnnonce()`, dernier
    // recours).
    'exchange_subject' => 'Entraide #:id',

    // TASK-1754 §3.2 — arbitrage Q2 de Cyril (05/11) : le BADGE reste court et
    // NEUTRE, c'est la PHRASE qui porte le sens (qui aide qui). Deux fois moins
    // de libellés à maintenir, et c'est la phrase qui supprime la confusion
    // constatée en recette — un badge, aussi bien écrit soit-il, ne dira jamais
    // « Ottoline dit avoir reçu l'aide, confirmez pour recevoir vos 30 min ».
    // TASK-1806 — ces six libelles partent AUSSI dans l'objet des courriels de
    // statut (`{{ status_label }}`). « À confirmer » nommait une etape qui
    // n'existe plus cote membre : le parcours visible en compte trois, et la
    // confirmation est le geste de l'aide. `buyer_done` est desormais un
    // statut HERITE que plus aucun code ne produit — son libelle ne promet
    // donc plus rien a personne.
    'status' => [
        'pending' => 'Proposition',
        'accepted' => 'Aide acceptée',
        'buyer_done' => 'Entraide en cours',
        'completed' => 'Entraide terminée',
        'refused' => 'Déclinée',
        'cancelled' => 'Annulé',
    ],

    // Les phrases orientées rôle. `:temps` reçoit le double affichage
    // (« 30 min · 30 pts »), jamais un nombre de points nu.
    'phrase' => [
        'pending_decideur' => ':prenom répond à « :titre » — :temps',
        'pending_proposant' => 'Votre proposition est partie. :prenom doit répondre.',
        // TASK-1806 — l'accord pris, chacun doit savoir ce qu'on attend de
        // LUI : l'aide confirmera, l'aidant attend cette confirmation. Une
        // phrase unique ne pouvait pas dire les deux.
        'accepted_aide' => 'Entraide convenue avec :prenom — :temps. Quand ce sera fait, confirmez pour clôturer.',
        'accepted_aidant' => 'Entraide convenue avec :prenom — :temps. :prenom confirmera quand ce sera fait.',
        // Statuts HERITES : plus aucun code ne produit `buyer_done`. Ces deux
        // phrases ne servent qu'a une eventuelle ligne ancienne, et elles
        // disent le nouveau denouement — c'est l'aide qui cloture.
        'buyer_done_aidant' => ':prenom doit confirmer pour clôturer cette entraide.',
        'buyer_done_aide' => ':prenom attend votre confirmation pour clôturer cette entraide — :temps.',
        'completed' => 'Entraide terminée — :temps transférés. Merci !',
        'refused_proposant' => ':prenom a décliné votre proposition.',
        'refused_decideur' => 'Vous avez décliné cette proposition.',
        'cancelled' => 'Cette entraide a été annulée.',
    ],

    // TASK-1754 §3.3 — des verbes d'entraide. `cancel` se dédouble : retirer SA
    // proposition n'est pas annuler un échange déjà convenu, et les confondre
    // sous « Annuler » cachait ce qui se passe vraiment.
    'actions' => [
        'accept' => 'Accepter l\'entraide',
        'refuse' => 'Décliner',
        'adjust' => 'Proposer un autre temps',
        'cancel' => 'Annuler',
        'cancel_withdraw' => 'Retirer ma proposition',
        'cancel_exchange' => 'Annuler l\'entraide',
        // TASK-1806 — `mark_done` est desormais le libelle du BOUTON DE
        // CLOTURE, dans la carte de bas de fil qui porte aussi l'evaluation.
        // C'est le seul geste de la 3e etape.
        'mark_done' => 'J\'ai bien reçu l\'aide',
        // HERITE : la confirmation de l'aidant a quitte le parcours visible.
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
    // TASK-1754 §3.4 — les faits du fil, desormais traduits.
    // `:temps` est reconstruit depuis les points au moment du rendu.
    'systeme' => [
        'proposition_envoyee' => '🤝 Proposition envoyée — :temps',
        // TASK-1806 — les CLES ne bougent pas : elles sont ecrites en base
        // dans `messages.metadata->system_key` et les renommer casserait le
        // rendu de tout l'historique (`MessageSysteme::rendre()` retombe sur
        // `body` pour une clef inconnue). Seules les VALEURS changent.
        'echange_accepte' => '✅ Aide acceptée. L’entraide est en cours.',
        'echange_decline' => 'Proposition déclinée.',
        'temps_ajuste' => 'Temps ajusté : :temps',
        'echange_annule' => 'Entraide annulée.',
        // HERITE : ce message n'est plus ecrit (l'aide confirme et cloture
        // d'un seul geste), il ne sert qu'a relire l'historique.
        'aide_recue' => ':prenom a confirmé avoir reçu l’aide.',
        'echange_termine' => '🎉 Entraide terminée — :temps partagées.',
        'pas_encore_termine' => 'L’aide n’est pas encore terminée : l’entraide est remise en cours.',
    ],
    // TASK-1755 §5.2 — le SENS de l'aide, sur chaque ligne de la liste.
    // C'est ce qui manquait le plus : la meme personne apparaissait N fois et
    // seul un badge les distinguait.
    'sens' => [
        'vous_aidez' => 'Vous aidez :prenom · :temps',
        'on_vous_demande' => ':prenom vous demande de l’aide · :temps',
        // TASK-1808 — les deux cas de l'AIDE, qui manquaient : il heritait de
        // la phrase de l'aidant et lisait l'inverse de la verite.
        'vous_demandez' => 'Vous demandez de l’aide à :prenom · :temps',
        'on_vous_aide' => ':prenom vous aide · :temps',
        'terminee' => 'Entraide terminée · :temps partagées',
    ],
    // TASK-1806 (arbitrage du 11/11) — TROIS etapes visibles, pas cinq.
    // La confirmation de l'aidant et l'evaluation ont ete fondues dans la 3e.
    'etapes' => [
        'demande' => 'Demande',
        'accord' => 'Accord',
        'confirmation' => 'Confirmation',
    ],
    'filtres' => [
        'tous' => 'Tous',
        'en_cours' => 'En cours',
        'discussions' => 'Discussions',
        'termines' => 'Terminés',
    ],
    'autres_actions' => 'Autres actions',
    'review_exchange' => 'Dites merci à :prenom',
    // TASK-1806 — l'intro ne repete plus « l'entraide est terminee, :temps
    // partagees » : la carte recapitulative du Bento le dit deja juste
    // au-dessus, le repeter ici serait un message redondant.
    'review_intro' => 'Votre mot de remerciement sera affiché sur son profil.',
    'review_placeholder' => 'Un mot de remerciement (affiché sur son profil)…',
    'review_rating_hint' => 'Choisissez une note.',
    'review_rating_required' => 'Choisissez une note avant d’envoyer.',
    'send_review' => 'Envoyer le merci',
    'review_sent' => 'Merci pour votre évaluation !',
    // TASK-1806 (arbitrage du 11/11) — la 3e et derniere etape : confirmer,
    // et dire merci dans le meme geste. Le mot « facultatif » est dit
    // explicitement : une personne qui ne veut pas noter doit le comprendre
    // sans avoir a chercher un bouton pour s'echapper.
    'confirmer_titre' => 'Avez-vous reçu l’aide de :prenom ?',
    'confirmer_intro' => 'En confirmant, l’entraide est terminée et :temps sont transférés à :prenom.',
    'confirmer_intro_offert' => 'En confirmant, l’entraide est terminée. :prenom vous a offert son aide : aucun point n’est transféré.',
    'confirmer_note_label' => 'Laisser une note et un mot de remerciement (facultatif)',
    'confirmer_sans_note' => 'Vous pouvez confirmer sans laisser d’avis.',
    // TASK-1808 §2/§3 — LA CLOTURE, en un bouton compact puis un panneau.
    'cloturer_bouton' => 'Marquer l’entraide comme terminée',
    'cloturer_titre' => 'Confirmer la fin de l’entraide',
    'cloturer_intro' => 'Cela clôture l’entraide et transfère :temps à :prenom. La conversation passera en lecture seule.',
    'cloturer_intro_offert' => 'Cela clôture l’entraide. :prenom a offert son aide : aucun point n’est transféré.',
    'cloturer_valider' => 'Confirmer',
    'en_cours_titre' => 'Entraide en cours',
    'en_cours_intro' => 'Discutez avec :prenom. Quand l’aide aura été reçue, confirmez-le ici.',
    // TASK-1808 §3 — ce que chacun lit APRES la cloture.
    'cloture_aide_titre' => 'Entraide terminée !',
    'cloture_aide_corps' => ':temps ont été transférés à :prenom.',
    'cloture_aide_corps_offert' => ':prenom a offert son aide : aucun point n’a été transféré.',
    'cloture_aidant_titre' => 'Merci pour votre aide !',
    'cloture_aidant_corps' => 'Vous avez reçu :temps de la part de :prenom.',
    'cloture_aidant_corps_offert' => 'Vous avez offert votre aide à :prenom.',
    // TASK-1809 (correctif Cyril, 12/11) — l'aidant voit sa note DANS le fil,
    // sans avoir besoin de l'email pour la decouvrir. Remplace le message
    // generique ci-dessus des qu'un avis existe.
    'cloture_aidant_evalue_titre' => ':prenom vient de vous laisser une évaluation',
    // TASK-1809 §2 — UNE carte compacte (note + commentaire facultatif en
    // une seule soumission), remplace les deux micro-ecrans de T1808 §5.
    'etoiles_titre' => 'Comment évaluez-vous l’aide de :prenom ?',
    'etoiles_valider' => 'Valider mon évaluation',
    // TASK-1809 (correctif Cyril, 12/11) — l'ecran qui remplace la carte une
    // fois l'avis depose ; le partage dans le Flux est desormais AUTOMATIQUE
    // (plus de case a cocher ni de lien distinct).
    'evaluation_effectuee_titre' => 'Évaluation effectuée',
    'evaluation_effectuee_lien' => 'Voir dans le Flux',
    'bento_title' => 'Entraide réalisée',
    'bento_duree' => 'Durée',
    'bento_duree_absente' => 'Durée non renseignée',
    'bento_points' => 'Points attribués',
    'bento_points_value' => '{1}:n pt|[2,*]:n pts',
    'bento_aidant' => 'Aidant',
    'bento_aide' => 'Aidé',
    'bento_contribution_title' => 'Vous avez aidé :prenom',
    'bento_contribution_body' => 'Merci pour votre contribution.',
    'composer_placeholder' => 'Votre message...',
    // TASK-1772 (recette Cyril) — le composeur de la Messagerie adopte la
    // pilule et le menu « + » du ChatLoop. Les libelles vivent ici et non
    // dans `loops.php` : cet ecran n'est pas une Boucle.
    'composer_more_actions' => 'Plus d\'actions',
    'composer_add_image' => 'Ajouter une image',
    'composer_add_file' => 'Ajouter un fichier',
    'composer_take_photo' => 'Prendre une photo',
    'conversation_closed' => 'Cette conversation est terminée.',
    'empty_thread' => 'Aucun message pour le moment',
    'empty_thread_hint' => 'Soyez le premier à écrire !',
    'write_message' => 'Écrivez un message...',
    'reply_to' => 'Réponse à',
    'image_preview' => 'Aperçu',
    'image_alt' => 'Image',
    'reply' => 'Répondre',
    // TASK-1770 — nom accessible de la feuille d'actions (appui long).
    'message_actions' => 'Actions sur ce message',
    // TASK-1771 — le mode selection (barre d'actions en haut).
    'selection_exit' => 'Quitter la sélection',
    'selection_one' => '1 message sélectionné',
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

    /*
     * TASK-1760 — le REPLI de l'email de statut (`emails/transaction_status`).
     * Le gabarit administrable en base reste le chemin normal ; ces libelles
     * servent le jour ou il est desactive. La PHRASE de statut, elle, vient
     * de `CarteEntraide::phrase()` : une seule definition, orientee role.
     */
    // TASK-1806 — le nom du GROUPE (`email_exchange`) reste : il est lu par
    // `emails/transaction_status.blade.php` et le renommer n'apporterait rien
    // de visible. Les VALEURS, elles, quittent le lexique marchand.
    'email_exchange' => [
        'title' => 'Mise à jour de votre entraide',
        'greeting' => 'Bonjour :prenom,',
        'intro' => 'Le statut de votre entraide a changé : **:statut**.',
        'label_exchange' => 'Entraide',
        'label_time' => 'Temps partagé',
        'cta' => 'Voir la conversation',
        'untitled' => 'Entraide',
        'subject' => 'Mise à jour de votre entraide — :statut',
    ],

    // TASK-1772 (recette Cyril, 06/11) — l'apercu d'annuaire au clic sur
    // l'avatar detache de la bulle.
    'member_preview_open' => 'Voir la fiche de :name',
    'member_preview_close' => 'Fermer',
    'member_preview_title' => 'Fiche d’annuaire',
    'member_preview_full' => 'Voir le profil complet',
    'member_preview_write' => 'Écrire un message privé',
    'member_preview_empty' => 'Cette personne n’a pas encore renseigné sa fiche.',
];
