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
];
