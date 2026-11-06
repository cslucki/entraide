<?php

return [
    'service_term' => 'proposition',
    'request_term' => 'demande d\'aide',
    'service_create_heading' => 'Proposer de l\'aide',
    'request_create_heading' => 'Demander de l\'aide',
    // =====================================================================
    // TASK-1773 — L'ECRAN QUI SUIT LA PUBLICATION D'UNE PROPOSITION.
    // =====================================================================
    // Le but est produit : encourager a publier plusieurs propositions, pour
    // que l'annuaire dise vraiment ce qu'un membre sait faire.
    'service_created_heading' => 'Proposition publiée',
    'service_created_title' => 'Proposition d\'entraide publiée !',
    'service_created_subtitle' => 'Merci de contribuer à l\'entraide sur BouclePro.',
    // Le cas ou la proposition n'est PAS active. `store()` ne le produit pas
    // aujourd'hui ; l'ecran lit le statut reel plutot que de le supposer.
    'service_created_title_paused' => 'Votre proposition d\'entraide est enregistrée.',
    'service_created_subtitle_paused' => 'Elle n\'est pas encore visible par les autres membres.',
    'service_created_more_title' => 'Mettez en avant vos compétences',
    'service_created_more_body_skills' => 'Vous avez certainement plusieurs compétences, expériences ou connaissances qui pourraient être utiles aux autres membres.',
    'service_created_more_body_directory' => 'Chaque nouvelle proposition enrichit votre profil dans l\'annuaire et permet aux autres membres de mieux découvrir ce que vous pouvez leur apporter.',
    'service_created_again' => 'Proposer une autre proposition d\'entraide',
    'service_created_see_mine' => 'Voir mes propositions',
    'service_created_exit' => 'Continuer ma navigation',
    'service_intro_title' => 'Ce que vous souhaitez apporter au collectif',
    'service_intro_body' => 'Partagez vos compétences, votre expérience, vos connaissances ou simplement votre temps pour aider d\'autres membres. Décrivez ce que vous proposez, comment vous souhaitez contribuer et en quoi votre aide peut être utile.',
    // TASK-1773 — cles DEDIEES au formulaire « Demander de l'aide ».
    // `marketplace.title` et `.description` servent encore `services/edit`,
    // donc on n'y touche pas : on en cree a cote. `delivery_mode`, lui, n'est
    // plus employe que par la demande — mais une cle dediee le dit, et elle
    // resistera au prochain partage.
    'request_title_label' => 'De quelle aide avez-vous besoin ?',
    'request_delivery_mode' => 'Comment souhaitez-vous être aidé ?',
    // Les deux paragraphes qui completent l'encadre, cote demande.
    'request_points_help_count' => 'Pour reconnaître les contributions de chacun, le temps consacré à l\'entraide est comptabilisé en points : 1 minute = 1 point.',
    'request_points_help_estimate' => 'Estimez le temps nécessaire pour recevoir cette aide. Les points correspondants permettent de valoriser le temps partagé entre membres.',
    // Le rappel sous les deux champs de duree.
    'request_duration_hint' => '1 minute d\'entraide = 1 point',
    'request_intro_title' => 'Ce que vous souhaitez demander au collectif',
    'request_intro_body' => 'Expliquez ce que vous souhaitez réaliser, les difficultés que vous rencontrez et le type d\'aide qui vous serait utile. Les autres membres pourront partager leur temps, leurs compétences ou leur expérience pour vous accompagner.',
    'title' => 'Titre',
    'required' => '*',
    'title_service_placeholder' => 'Ex : Création de logo professionnel, Traduction FR→EN, Conseil comptable 1h…',
    'description' => 'Description',
    'min_chars' => '(:count caractères minimum)',
    'service_description_placeholder' => "Décrivez ce que vous proposez concrètement :\n- Ce que le membre reçoit exactement\n- Comment vous travaillez\n- Vos conditions (délai, format de livraison…)\n\nExemple : Je réalise votre logo professionnel en 3 propositions avec retouches illimitées sous 5 jours. Formats livrés : PNG, SVG, PDF. Je travaille sur Figma et Illustrator.",
    'request_description' => 'Précisez votre besoin',
    // TASK-1773 — le champ n'avait aucun guide ; l'editeur lui en donne un.
    'request_description_placeholder' => "Décrivez votre besoin :\n- Ce dont vous avez besoin, précisément\n- Le résultat attendu\n- Vos contraintes (délai, format, lieu…)",
    'category' => 'Catégorie',
    'select' => 'Sélectionner...',
    'skills' => 'Compétences',
    'images' => 'Images (max 5)',
    'image_help' => 'JPG, PNG ou WebP — max 2 Mo par image',
    'tags' => 'Tags',
    'max_5' => '(max 5)',
    'max_10' => '(max 10)',
    'add_tag_placeholder' => 'Ajouter un tag...',
    'add' => 'Ajouter',
    'delivery_mode' => 'Comment se passe l’aide',
    'delivery' => [
        'remote' => '🌐 À distance',
        'onsite' => '📍 Sur site',
        'both' => '🌐📍 Les deux',
    ],
    // =====================================================================
    // TASK-1773 — LES TEXTES DU FORMULAIRE « PROPOSER DE L'AIDE ».
    // =====================================================================
    // Des cles DEDIEES, et non la modification des cles generiques
    // (`title`, `description`, `images`, `tags`, `delivery_mode`,
    // `points_requested`, `points_help_title`) : celles-la servent AUSSI le
    // formulaire de DEMANDE d'aide, que la consigne interdit de toucher.
    // Les modifier aurait change les deux surfaces d'un coup, sans la moindre
    // erreur nulle part — un degat qui ne se voit qu'en recette.
    //
    // `service_intro_*` et `publish_service` sont, eux, propres a cette
    // surface : ils sont donc modifies sur place.
    'service_title_label' => 'Que souhaitez-vous proposer ?',
    'service_description_label' => 'Décrivez votre proposition d\'aide',
    'service_images_label' => 'Illustrations (facultatif)',
    'service_tags_label' => 'Mots-clés (facultatif)',
    'service_delivery_mode' => 'Comment souhaitez-vous aider ?',

    // Le champ de duree. La REGLE METIER ne bouge pas : la colonne reste
    // `points_cost`, la validation et les bornes de l'Organization aussi.
    // Seule la PRESENTATION change — 1 minute = 1 point, donc le nombre saisi
    // se lit indifferemment en minutes ou en points.
    'service_duration_label' => 'Temps estimé pour cette aide',
    'service_duration_help' => 'Indiquez le temps que vous prévoyez de consacrer à cette aide.',
    'service_duration_equivalent' => 'Équivalent : :points points',
    'service_duration_unit' => 'minutes',

    // L'encadre d'explication. Le lien vers le profil public et son texte
    // d'accompagnement (`profile_tip_*`, `public_profile`) sont CONSERVES.
    // TASK-1773 (recette Cyril) — meme phrase, version « vous devez ».
    // Dediee : `profile_tip_before`/`_after` servent aussi la demande d'aide.
    'service_profile_tip_before' => '✨ N\'oubliez pas que vous devez présenter votre activité sur votre',
    'service_profile_tip_after' => 'pour qu\'ils apparaissent dans l\'annuaire.',
    'service_points_help_title' => '💡 Comment fonctionne l\'échange de temps ?',
    'service_points_help_share' => 'Sur :organization, chacun peut partager son temps, ses compétences et son expérience avec les autres membres.',
    'service_points_help_count' => 'Pour reconnaître les contributions de chacun, le temps consacré à l\'entraide est comptabilisé en points : 1 minute = 1 point.',
    'service_points_help_reuse' => 'Ces points permettent à leur tour de bénéficier de l\'aide d\'autres membres.',
    'points_help_title' => '💡 Comment fonctionne l\'échange de temps ?',
    'points_service_body' => ':organization utilise les points comme unité d\'échange pour évaluer la valeur d\'une proposition entre membres.',
    'points_request_body' => 'Sur :organization, chacun peut demander de l\'aide et bénéficier du temps, des compétences et de l\'expérience des autres membres.',
    'points_one_minute' => '1 minute = 1 point.',
    'points_minimum_allowed' => 'Minimum autorisé dans :organization : :min points.',
    'points_maximum_allowed' => 'Maximum autorisé dans :organization : :max points.',
    'indicative_ranges' => 'Fourchettes indicatives (tout est négociable) :',
    'level_essential' => 'Essentiel',
    'level_standard' => 'Standard',
    'level_complete' => 'Complet',
    'duration_20_30' => '(20 à 30 min)',
    'duration_30_45' => '(30 à 45 min)',
    'duration_45_60' => '(45 à 60 min)',
    'service_points_limits' => 'Les bornes autorisées sont définies par l\'organisation courante.',
    'request_points_hint' => 'Indiquez le budget qui correspond à votre besoin dans le barème autorisé.',
    'profile_tip_before' => '✨ N\'oubliez pas que vous pouvez présenter votre activité plus largement (liens site web, LinkedIn) sur votre',
    'public_profile' => 'profil public',
    'profile_tip_after' => 'pour qu\'ils apparaissent dans l\'annuaire.',
    'points_requested' => 'Points demandés',
    'recommended_ranges_category' => 'Fourchettes recommandées pour cette catégorie :',
    'indicative_ranges_level' => 'Fourchettes indicatives par niveau :',
    'publish_service' => 'Publier ma proposition',
    'publish_request' => 'Publier ma demande',
    'toolbar_bold' => 'Gras',
    'toolbar_bold_title' => 'Gras (sélectionnez du texte)',
    'toolbar_link' => 'Lien',
    'toolbar_link_title' => 'Insérer un lien',
    'toolbar_h2_title' => 'Titre niveau 2',
    'toolbar_h3_title' => 'Titre niveau 3',
    'toolbar_list' => 'Liste',
    'toolbar_list_title' => 'Liste à puces',
    'budget_min' => 'Temps d\'aide minimum estimé (minutes)',
    'budget_max' => 'Temps d\'aide maximum estimé (minutes)',
    'optional' => 'optionnel',
    'attachments' => 'Documents ou images',
    'attachments_help' => 'optionnelles — max 5 fichiers · 10 Mo chacun',
    'attachments_add' => 'Cliquer pour ajouter des images ou documents',
    'attachments_types' => 'JPG · PNG · PDF · Word · Excel',
    'file_size_mb' => ':size Mo',
    'deadline' => 'Date à laquelle vous souhaitez être aidé',
    'validation' => [
        'required' => 'Renseignez le champ :attribute.',
        'select_required' => 'Sélectionnez une valeur pour :attribute.',
        'radio_required' => 'Choisissez une option pour :attribute.',
        'too_short' => 'Le champ :attribute doit contenir au moins :min caractères.',
        'too_long' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
        'range_underflow' => 'Le champ :attribute doit être au moins :min.',
        'range_overflow' => 'Le champ :attribute ne peut pas dépasser :max.',
        'type_mismatch' => 'Le format du champ :attribute est invalide.',
        'bad_input' => 'Le champ :attribute doit contenir une valeur valide.',
        'file_type' => 'Le fichier sélectionné pour :attribute n’est pas valide.',
    ],
    'validation_attributes' => [
        'title' => 'titre',
        'description' => 'description',
        'category_id' => 'catégorie',
        'delivery_mode' => 'comment se passe l’aide',
        'points_cost' => 'points demandés',
        'budget_min' => 'budget minimum',
        'budget_max' => 'budget maximum',
        'deadline' => 'date souhaitée',
        'status' => 'statut',
        'skills' => 'compétences',
        'skills.*' => 'compétence',
        'tags' => 'tags',
        'images' => 'images',
        'images.*' => 'image',
        'delete_images' => 'images à supprimer',
        'delete_images.*' => 'image à supprimer',
        'attachments' => 'pièces jointes',
        'attachments.*' => 'pièce jointe',
        'delete_attachments' => 'pièces jointes à supprimer',
        'delete_attachments.*' => 'pièce jointe à supprimer',
    ],
    // TASK-1693 bug X
    'tag_duplicate' => 'Ce tag est déjà dans la liste.',
    'images_trimmed' => ':count image(s) ecartee(s) : la limite est de :max. Les :max premieres ont ete conservees.',
];
