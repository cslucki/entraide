<?php

return [
    'failed' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
    'deactivated' => 'Votre compte a été désactivé. Veuillez contacter l\'administrateur.',
    // TASK-1716 — un message DISTINCT de `deactivated`. « Contactez
    // l'administrateur » envoyait reclamer une explication a quelqu'un qui
    // venait de fermer son compte lui-meme : le message ne lui apprenait rien
    // et laissait croire a une sanction.
    'closed_by_owner' => 'Vous avez fermé ce compte. Contactez un administrateur si vous souhaitez le réouvrir ou demander l\'effacement de vos données personnelles.',
    'password' => 'Le mot de passe fourni est incorrect.',
    'throttle' => 'Tentatives de connexion trop nombreuses. Veuillez essayer de nouveau dans :seconds secondes.',

    // Inscription
    'register_title' => 'Créer un compte',
    'register_title_org' => 'Rejoindre :name',
    'register_subtitle' => 'Rejoignez la boucle Entraide',
    'register_subtitle_org' => 'Créez votre compte sur :name',
    'register_button' => 'Créer mon compte',

    // TASK-1714 volet 1 — le menu d'organisation, apres le prenom.
    'organization_choice_label' => 'Votre organisation',
    'organization_choice_placeholder' => 'Choisissez votre organisation…',
    'organization_choice_current' => 'vous êtes ici',
    'organization_choice_hint' => 'Vous ne savez pas laquelle choisir ? Restez sur BouclePro, vous pourrez rejoindre une organisation plus tard.',
    'first_name_label' => 'Votre prénom',
    'first_name_placeholder' => 'Jean',
    'name_label' => 'Votre nom',
    'name_placeholder' => 'Dupont',
    'email_label' => 'Adresse e-mail',
    'phone_label' => 'Numéro de téléphone',
    'phone_placeholder' => '+33 6 12 34 56 78',
    'country_label' => 'Pays',
    'country_placeholder' => 'Sélectionnez votre pays',
    'password_label' => 'Mot de passe',
    'password_placeholder' => '8 caractères minimum',
    'password_confirm_label' => 'Confirmer le mot de passe',
    'has_account' => 'Déjà un compte ?',
    'login_link' => 'Se connecter',

    // Connexion
    'login_title' => 'Connexion',
    'login_subtitle_org' => 'Connectez-vous à :name',
    'login_button' => 'Se connecter',
    'remember_me' => 'Se souvenir de moi',
    'forgot_password_link' => 'Mot de passe oublié ?',
    'no_account' => 'Pas encore de compte ?',
    'register_link' => 'Créer un compte',

    // Mot de passe oublié
    'forgot_password_title' => 'Mot de passe oublié',
    'forgot_password_subtitle' => 'Renseignez votre e-mail et nous vous enverrons un lien de réinitialisation.',
    'forgot_password_button' => 'Envoyer le lien de réinitialisation',
    'back_to_login' => 'Retour à la connexion',
    'logout' => 'Déconnexion',

    // TASK-1714 volet 2 — l'attente de validation par un administrateur.
    'approval_pending_title' => 'Votre compte attend une validation',
    'approval_pending_intro' => 'Un administrateur doit valider votre compte avant que vous puissiez accéder à la plateforme.',
    'approval_pending_intro_org' => 'Un administrateur de :name doit valider votre compte avant que vous puissiez y accéder.',
    'approval_pending_email_done' => 'Votre adresse e-mail est bien confirmée : vous n\'avez rien oublié, et rien d\'autre ne vous est demandé.',
    'approval_pending_next' => 'Vous recevrez un e-mail dès que votre compte sera validé. Vous pourrez alors vous connecter normalement.',
    'account_pending_approval' => 'Votre compte attend la validation d\'un administrateur.',

    // Réinitialisation
    'reset_password_title' => 'Nouveau mot de passe',
    'reset_password_subtitle' => 'Choisissez un nouveau mot de passe pour votre compte.',
    'new_password_label' => 'Nouveau mot de passe',
    'reset_password_button' => 'Réinitialiser le mot de passe',

    // TASK-1728 (A3) — le selecteur d'Organization du formulaire global,
    // et la sortie de secours du parcours d'Organization.
    'login_organization_label' => "Organisation (facultatif)",
    'login_organization_any' => "— Toutes les organisations —",
    'login_organization_hint' => "Utile seulement si votre adresse est rattachée à plusieurs organisations. Laissez vide sinon.",
    'login_try_global' => "Votre compte appartient à une autre organisation ? Connectez-vous ici.",

    // TASK-1730 (A5a) — la bascule entre comptes d'Organizations differentes.
    //
    // `switch_failed` couvre CINQ causes d'un seul message : organisation
    // inconnue, adresse absente, ambiguite, mot de passe faux, compte
    // inutilisable. Les distinguer ferait de cet ecran un oracle
    // d'appartenance ; le motif precis part au journal.
    'switch_failed' => 'Impossible de passer sur ce compte. Verifiez l\'organisation choisie et le mot de passe de ce compte.',
    'switch_already_here' => 'Vous etes deja connecte sur le compte de cette organisation.',
    'switch_title' => 'Changer de compte',
    'switch_intro' => 'Si votre adresse porte un compte dans une autre organisation, indiquez laquelle et saisissez le mot de passe DE CE COMPTE. Vous serez deconnecte du compte actuel.',
    'switch_organization_label' => 'Organisation du compte visé',
    'switch_organization_placeholder' => 'Choisir une organisation',
    'switch_password_label' => 'Mot de passe du compte visé',
    'switch_submit' => 'Passer sur ce compte',

    // TASK-1731 (A5b) — la liaison explicite et revocable entre comptes.
    //
    // `link_failed` couvre plusieurs causes d'un seul message : organisation
    // inconnue, adresse absente, ambiguite, l'un ou l'autre mot de passe faux.
    // Les distinguer permettrait d'eprouver le mot de passe d'un compte qu'on
    // ne controle pas. `link_already_exists` est la seule exception : il ne dit
    // rien que la personne ne lise deja sur sa propre liste.
    'link_failed' => 'Impossible de lier ces comptes. Verifiez l\'organisation, l\'adresse et les deux mots de passe.',
    'link_already_exists' => 'Ces deux comptes sont deja lies.',
    'link_created' => 'Les deux comptes sont maintenant lies. Vous pourrez passer de l\'un a l\'autre sans redonner le mot de passe de l\'autre compte.',
    'link_revoked' => 'Le lien est revoque. Il faudra de nouveau le mot de passe du compte vise pour y passer.',
    'links_title' => 'Comptes lies',
    'links_intro' => 'Lier deux de vos comptes permet de passer de l\'un a l\'autre sans redonner le mot de passe de l\'autre compte. Les donnees des deux organisations restent separees, et vous pouvez revoquer le lien a tout moment, depuis l\'un comme l\'autre.',
    'links_empty' => 'Aucun compte lie pour l\'instant.',
    'links_add_title' => 'Lier un autre de vos comptes',
    'links_target_email' => 'Adresse du compte a lier',
    'links_target_password' => 'Mot de passe de ce compte',
    'links_own_password' => 'Votre mot de passe actuel',
    'links_submit' => 'Lier ce compte',
    'links_revoke' => 'Revoquer',
    'links_switch' => 'Passer sur ce compte',
    'links_confirm_password' => 'Votre mot de passe (confirmation)',

    // TASK-1734 — « Mes organisations ». Le vocabulaire parle
    // d'ORGANISATIONS, jamais de « comptes », « liaisons » ou « bascule » :
    // c'est un critere d'acceptation. `myorg_refused` couvre TOUS les motifs
    // d'un seul message (meme discipline que `switch_failed`).
    'myorg_refused' => 'Impossible d\'accéder à cette organisation pour le moment.',
    'myorg_refused_title' => 'Accès impossible',
    'myorg_back' => 'Retour',
    'myorg_sent_title' => 'Un email vient de vous être envoyé',
    'myorg_sent_body' => 'Pour confirmer que vos deux comptes sont bien à vous, cliquez sur le lien envoyé à :email. Vous pourrez ensuite passer sur :organization en un clic.',
    'myorg_sent_once' => 'Le lien est valable 15 minutes. Vous ne ferez cette confirmation qu\'une seule fois pour cette organisation.',
    'myorg_confirm_title' => 'Confirmer, et continuer',
    'myorg_confirm_body' => 'En confirmant, vous pourrez passer de votre organisation actuelle à :organization en un clic, sans mot de passe. Vos données restent séparées, et vous pourrez retirer cet accès à tout moment.',
    'myorg_confirm_submit' => 'Confirmer et continuer',
    'myorg_confirm_not_you' => 'Ce n\'était pas vous ? Fermez simplement cette page : rien ne sera modifié.',
    'myorg_invalid_title' => 'Ce lien n\'est plus valable',
    'myorg_invalid_body' => 'Le lien a expiré ou a déjà servi. Retournez dans le menu de votre avatar et choisissez de nouveau l\'organisation : un nouvel email vous sera envoyé.',
    'myorg_wrong_account_title' => 'Ouvrez ce lien depuis le bon compte',
    'myorg_wrong_account_body' => 'Ce lien doit être ouvert depuis le compte avec lequel la demande a été faite. Reconnectez-vous avec ce compte, puis cliquez de nouveau sur le lien de l\'email.',
    'myorg_linked_flash' => 'Vos comptes sont liés. Vous pouvez maintenant passer d\'une organisation à l\'autre en un clic, et retirer cet accès à tout moment depuis vos réglages.',
    // Replis si le gabarit d'email venait a manquer (jamais un email vide).
    'myorg_mail_confirm_subject' => 'Confirmez la liaison de vos deux comptes',
    'myorg_mail_confirm_line' => 'Vous avez demandé à passer de :source à :target. Si c\'est bien vous, confirmez : vous pourrez ensuite passer de l\'une à l\'autre en un clic.',
    'myorg_mail_confirm_action' => 'Confirmer et continuer',
    'myorg_mail_confirm_ignore' => 'Ce n\'était pas vous ? Ignorez ce message : rien ne sera lié.',
    'myorg_mail_linked_subject' => 'Vos comptes sont maintenant liés',
    'myorg_mail_linked_line' => 'Votre compte et votre compte de :other sont maintenant liés. Vous pouvez révoquer ce lien à tout moment, depuis l\'un ou l\'autre compte.',
    'myorg_mail_linked_action' => 'Gérer mes comptes liés',
];
