<?php

// TASK-1659 — invitation directe a une Organization (creation de comptes en
// masse, SuperAdmin). Distinct de loops.php : pas de message personnalise,
// pas de formulaire d'inscription intermediaire.

return [

    // E-mail (repli Blade, quand le SystemEmailTemplate 'organization_invitation' manque)
    'mail_subject' => 'Votre accès à :organization',
    'mail_heading' => 'Vous êtes invité·e à rejoindre :organization',
    'mail_greeting' => 'Bonjour :name,',
    'mail_body' => ':sender vous invite à rejoindre :organization sur BouclePro.',
    'mail_cta' => 'Rejoindre :organization',
    'mail_expires' => 'Ce lien expire le :date.',

    // Page publique d'atterrissage
    'landing_eyebrow' => 'Invitation BouclePro',
    'landing_unknown_organization' => 'Organisation',
    'landing_sent_to' => 'Invitation envoyée à',
    'landing_expires_on' => 'Expire le',
    'landing_revoked' => 'Ce lien d\'invitation a été révoqué.',
    'landing_expired' => 'Ce lien d\'invitation a expiré.',
    'landing_already_accepted' => 'Cette invitation a déjà été acceptée.',
    'landing_body' => 'Cliquez sur le bouton ci-dessous pour créer votre accès à :organization.',
    'landing_cta_join' => 'Rejoindre :organization',
    'landing_cta_enter' => 'Accéder à mon espace',
    'landing_no_password_needed' => 'Vous choisirez votre mot de passe juste après.',

    // Deuxieme etape : definir son mot de passe
    'password_title' => 'Choisissez votre mot de passe',
    'password_intro' => 'Votre accès est créé. Définissez un mot de passe pour pouvoir vous reconnecter ensuite.',
    'password_label' => 'Mot de passe',
    'password_confirm_label' => 'Confirmez le mot de passe',
    'password_cta' => 'Enregistrer et continuer',
    'password_set' => 'Votre mot de passe est enregistré. Bienvenue !',

    // Redirections apres tentative d'acceptation
    'welcome' => 'Bienvenue dans :organization.',
    'flash_expired' => 'Ce lien d\'invitation a expiré.',
    'flash_revoked' => 'Ce lien d\'invitation a été révoqué.',
    'flash_sandbox_forbidden' => 'Cette organisation n\'accepte pas d\'invitation directe.',
    'flash_email_used_elsewhere' => 'Cette adresse est déjà associée à un autre compte BouclePro.',
    'flash_invalid' => 'Ce lien d\'invitation n\'est pas valide.',

    // Surface SuperAdmin
    'admin_sandbox_forbidden' => 'Cette organisation est un monde de démonstration : aucune invitation réelle n\'y est envoyée.',
    'admin_resent' => 'Invitation relancée.',
    'admin_resend_impossible' => 'Cette invitation ne peut plus être relancée.',
    'admin_revoked' => 'Invitation révoquée.',
    'admin_revoke_impossible' => 'Seule une invitation en attente peut être révoquée.',

];
