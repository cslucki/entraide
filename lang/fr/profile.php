<?php

return [
    // TASK-1755 §9.6 — le profil comme bilan d'entraide, pas comme solde.
    'bilan' => [
        'personnes_aidees' => '{0}personne aidée|{1}personne aidée|[2,*]personnes aidées',
        'fois_aide' => '{0}fois aidé|{1}fois aidé|[2,*]fois aidé',
        'temps_offert' => 'de temps offert',
        'mercis' => '{0}aucun merci reçu|{1}:count merci reçu|[2,*]:count mercis reçus',
    ],

    'back_to_directory' => 'Retour à l’annuaire',
    'edit_profile' => 'Modifier mon profil',
    'publish_to_blog' => 'Publier dans le blog',
    'available' => 'Disponible',
    'unavailable' => 'Indisponible',
    'deactivated_user' => 'Utilisateur désactivé',
    'exchanges' => ':count échange|:count échanges',
    'points' => ':count pts',
    'about' => 'À propos',
    'can_offer' => 'Ce que ce membre peut vous apporter',
    'skills' => 'Compétences',
    'services_offered' => '{0} Propositions|{1} Proposition|[2,*] Propositions',
    'ai_agent_available' => 'Agent IA disponible',
    'ai_agent_available_desc' => 'Ce membre a activé son agent IA. Vous pouvez lui poser des questions sur son parcours, ses compétences et ses disponibilités.',
    'ai_agent_enabled' => 'Agent IA activé',
    'ai_agent_enabled_desc' => 'Les visiteurs peuvent interagir avec votre profil via l’agent IA.',
    'view_exchanges' => 'Voir les échanges',
    'agent_cta_title' => 'Agent de profil IA',
    'agent_cta_hint' => 'Posez une question sur ce que ce membre peut vous apporter.',
    'start' => 'Démarrer',
    'write_to' => 'Écrire à :name',
    'start_conversation' => 'Démarrer une conversation humaine directe.',
    'services_count' => ':count proposition|:count propositions',
    'no_services' => 'Aucun service actif pour le moment.',
    'help_section' => '{0} Demandes|{1} Demande|[2,*] Demandes',
    'requests_count' => ':count demande|:count demandes',
    'before_deadline' => 'Avant le :date',
    'blog_section' => 'Blog',
    // TASK-1788 correctif 05 — le titre de la carte d'apercu, au meme
    // gabarit que « Blog » juste au-dessus.
    'stats_section' => 'Stats',
    'blog_count' => ':count article|:count articles',
    'no_posts' => 'Cet utilisateur n’a pas encore publié d’article.',
    'reviews_section' => 'Évaluations reçues',
    'report' => 'Signaler',
    'report_user' => 'Signaler cet utilisateur',
    'report_reason' => 'Motif...',
    'report_abuse' => 'Comportement abusif',
    'report_scam' => 'Arnaque ou fraude',
    'report_fake' => 'Faux profil',
    'report_other' => 'Autre',
    'report_details' => 'Détails (optionnel)...',
    'report_send' => 'Envoyer',
    'report_cancel' => 'Annuler',
    'edit_title' => 'Modifier mon profil',

    // TASK-1824 — la page « Sécurité du compte ».
    'security_title' => 'Sécurité du compte',
    'security_intro' => 'Votre mot de passe, vos accès et la fermeture de votre compte.',
    'security_cta' => 'Sécurité du compte',
    'security_back_to_profile' => 'Retour au profil',
    'current_organization' => 'Organisation active : :name',

    'info_title' => 'Informations du profil',
    'info_description' => 'Mettez à jour les informations de votre compte et votre adresse e-mail.',
    'presentation_section' => 'Présentation',
    'presentation_description' => 'Ces informations servent à présenter votre profil aux autres membres.',
    'first_name' => 'Prénom',

    // TASK-1762 point 1 — la structure : société, association, collectif.
    'structure' => 'Structure',

    // TASK-1762 point 16 — les trois sections du formulaire remanie.
    'identity_section' => 'Informations de profil',
    'identity_description' => 'Qui vous êtes, et comment vous joindre.',
    'locale_section' => 'Langue principale',
    'locale_description' => 'La langue dans laquelle la plateforme s\'adresse à vous.',
    'slug_on' => 'Adresse de votre profil sur :organization',

    // TASK-1762 point 3 — le bouton en haut de l'edition du profil.
    'view_public_profile' => 'Voir mon profil public',

    // TASK-1762 point 16 — le plein écran de l'éditeur de présentation.
    'editor_fullscreen' => 'Plein écran',
    'editor_exit_fullscreen' => 'Quitter le plein écran',
    'structure_help' => 'Votre société, association ou collectif, si vous en avez une.',

    // TASK-1762 point 17 — la baseline obligatoire, « en une phrase ».
    'slogan' => 'Slogan',
    'slogan_help' => 'Ce qui vous définit en une phrase.',
    'name' => 'Nom',
    'email' => 'Adresse e-mail',
    'phone' => 'Numéro de téléphone *',
    'phone_placeholder' => 'Ex: 06 12 34 56 78',
    'phone_help' => 'Obligatoire pour pouvoir publier des services ou demandes.',
    'privacy' => 'Confidentialité',
    'show_email' => 'Afficher mon adresse e-mail sur mon profil public et dans l’annuaire',
    'show_phone' => 'Afficher mon numéro de téléphone sur mon profil public et dans l’annuaire',
    'location' => 'Localisation (Ville, Département)',
    'location_placeholder' => 'Ex: Lyon, Rhône',
    'bio' => 'Présentation',
    'bio_help' => 'Décrivez qui vous êtes, vos expertises, votre parcours. Cette présentation apparaît sur votre profil public et rassure les membres avant de vous contacter.',
    'bio_placeholder' => 'Ex : Consultante RH indépendante depuis 2015, je m’occupe de recrutement, de formation et d’accompagnement managérial pour les PME...',
    'bio_max' => 'Max 500 caractères',
    'bio_remaining' => ':count restants',
    'city' => 'Ville',
    'country' => 'Pays',
    'country_placeholder' => 'Sélectionner un pays',
    'preferred_locale' => 'Langue principale',
    'preferred_locale_placeholder' => 'Sélectionner une langue',
    'locale_fr' => 'Français',
    'locale_en' => 'Anglais',
    'membership' => 'Appartenance',
    'private_billing_section' => 'Informations privées / facturation',
    'private_billing_description' => 'Ces informations restent privées et ne sont pas affichées sur votre profil public.',
    'address_line1' => 'Adresse',
    'address_line2' => 'Complément d’adresse',
    'postal_code' => 'Code postal',
    'website' => 'Site web',
    'linkedin' => 'Profil LinkedIn',

    // TASK-1750 — « Autres liens » : plusieurs URL typees, sous les deux
    // champs ci-dessus.
    'links_heading' => 'Autres liens',
    'links_help' => 'Ajoutez vos autres adresses. Elles apparaitront sur votre profil public.',
    'links_add' => 'Ajouter un lien',
    'links_remove' => 'Retirer ce lien',
    'link_type_instagram' => 'Instagram',
    // TASK-1762 point 18 — demande de Cyril : le reseau a change de nom.
    'link_type_twitter' => 'X (ex Twitter)',
    'link_type_facebook' => 'Facebook',
    'link_type_website' => 'Site web',
    'link_type_blog' => 'Blog',
    'link_type_app' => 'Application',
    'avatar' => 'Avatar',
    'avatar_help' => 'JPG, PNG ou GIF — max 2 Mo',
    // TASK-1749 — la fenetre « changer ma photo », ouverte par le crayon de
    // l'avatar sur le profil public.
    'avatar_change' => 'Changer ma photo',
    'avatar_modal_help' => 'Elle apparaît sur votre profil, vos messages et vos Boucles.',
    'avatar_drop' => 'Glissez une photo ici, ou cliquez pour la choisir',
    'avatar_save' => 'Enregistrer la photo',
    'avatar_updated' => 'Votre photo a été mise à jour.',
    // TASK-1788 correctif 02 — la SUPPRESSION de la photo. Jusqu'ici on
    // pouvait la remplacer, jamais l'enlever : avatar nul = initiales generees.
    'avatar_remove' => 'Supprimer ma photo',
    'avatar_remove_cancel' => 'Annuler la suppression',
    'avatar_remove_pending' => 'Votre photo sera supprimée à l\'enregistrement. Vos initiales la remplaceront.',
    'avatar_remove_confirm' => 'Supprimer la photo',
    'avatar_removed' => 'Votre photo a été supprimée.',
    'available_label' => 'Je suis disponible pour des échanges',
    'save' => 'Enregistrer',
    'saved' => 'Enregistré.',
    'cancel' => 'Annuler',

    'password_section_heading' => 'Modifier le mot de passe',
    'password_section_description' => 'Utilisez un mot de passe long et aléatoire pour sécuriser votre compte.',
    'current_password' => 'Mot de passe actuel',
    'new_password' => 'Nouveau mot de passe',
    'confirm_password' => 'Confirmer le mot de passe',

    // TASK-1825 — confirmation premium apres un changement de mot de passe reussi.
    'password_updated_success' => 'Votre mot de passe a été modifié avec succès.',
    'password_updated_success_detail' => 'Votre nouveau mot de passe est désormais actif pour ce compte.',

    // TASK-1716 — « supprimer » devient « fermer », parce que c'est ce qui se
    // passe reellement : le compte est desactive, pas efface. Les anciennes
    // clefs `delete_account_*` sont SUPPRIMEES plutot que laissees en place —
    // les garder aurait laisse deux vocabulaires cohabiter, et le premier
    // promettait un effacement definitif qui n'a jamais eu lieu (il levait un
    // 500, voir `ProfileController::close()`).
    'close_account' => 'Fermer mon compte',
    'close_account_warning' => 'La fermeture désactive immédiatement votre compte : vous êtes déconnecté, vos accès sont révoqués et votre profil n\'est plus visible. Vos contributions et votre historique sont conservés. Seul un administrateur peut rouvrir un compte fermé.',
    'close_account_confirm' => 'Êtes-vous sûr de vouloir fermer votre compte ?',
    'close_account_instructions' => 'Saisissez votre mot de passe pour confirmer la fermeture de votre compte.',
    'close_account_password' => 'Mot de passe',
    'close_account_submit' => 'Fermer mon compte',
    'close_account_erasure' => 'Demander également l\'effacement de mes données personnelles',
    'close_account_erasure_help' => 'Un administrateur examinera votre demande. Certaines données ne peuvent pas être effacées lorsqu\'elles engagent une autre personne — un échange, un vote ou une écriture de points, par exemple.',
    'close_account_done' => 'Votre compte est fermé. Vos accès ont été révoqués.',

    'profile_reminder_title' => 'Complétez votre profil avant de publier.',
    'profile_reminder_body' => 'Les champs :fields sont obligatoires — les membres ont besoin de savoir à qui ils ont affaire avant d\'échanger.',
    'reminder_fields' => 'Prénom, Nom, Téléphone, Ville, Pays et Présentation',
    // TASK-1693 bug Z — l'adresse publique du profil.
    'slug' => 'Adresse de votre profil',
    'slug_prefix' => '…/profile/',

    // TASK-1762 point 4 bis — l'adresse qui sert AUJOURD'HUI, a droite du libelle.
    'slug_current' => 'Actuellement :',
    'slug_help' => 'Choisissez une adresse qui vous représente, par exemple :exemple ou :exemple_court',
    'slug_taken' => 'Cette adresse est déjà prise dans votre Organisation. Choisissez-en une autre.',
    // TASK-1762 point 4 — la regle de forme a quitte l'aide permanente pour
    // ce message : c'est le seul moment ou elle sert. La LONGUEUR y entre
    // aussi, sinon une adresse de deux lettres serait refusee sans raison dite.
    'slug_invalid' => 'Cette adresse n\'est pas utilisable : lettres minuscules, chiffres et tirets uniquement, entre 3 et 60 caractères, et certains mots sont réservés.',
    // TASK-1697 — les deux cards ajoutees a la fiche profil
    'loops_section' => 'Boucles',
    'loops_count' => ':count Boucle|:count Boucles',
    'comments_section' => 'Derniers commentaires',
    'comments_count' => ':count commentaire|:count commentaires',
    'comments_read_article' => 'Lire l’article',

    // TASK-1734 — la carte « Securite des comptes » du profil.
    'account_access_title' => 'Sécurité des comptes',
    'account_access_intro' => 'Pour passer d\'une organisation à l\'autre, utilisez « Mes organisations » dans le menu de votre avatar. Les parcours avancés — lier deux comptes d\'adresses différentes, passer sur un compte non proposé, retirer un accès — vivent ici.',
];
