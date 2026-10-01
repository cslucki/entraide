<?php

// TASK-1659 — direct Organization invitation (bulk account creation,
// SuperAdmin). Distinct from loops.php: no personal message, no
// intermediate registration form.

return [

    // E-mail (Blade fallback, when the 'organization_invitation' SystemEmailTemplate is missing)
    'mail_subject' => 'Your access to :organization',
    'mail_heading' => 'You are invited to join :organization',
    'mail_greeting' => 'Hi :name,',
    'mail_body' => ':sender invites you to join :organization on BouclePro.',
    'mail_cta' => 'Join :organization',
    'mail_expires' => 'This link expires on :date.',

    // Public landing page
    'landing_eyebrow' => 'BouclePro invitation',
    'landing_unknown_organization' => 'Organization',
    'landing_sent_to' => 'Invitation sent to',
    'landing_expires_on' => 'Expires on',
    'landing_revoked' => 'This invitation link has been revoked.',
    'landing_expired' => 'This invitation link has expired.',
    'landing_already_accepted' => 'This invitation has already been used. Your account exists: sign in with your email address.',
    'landing_cta_sign_in' => 'Sign in',
    'landing_forgot_password_hint' => 'Haven\'t chosen a password yet? Use "Forgot password" on the sign-in page.',
    'landing_body' => 'Click the button below to create your access to :organization.',
    'landing_cta_join' => 'Join :organization',
    'landing_cta_enter' => 'Go to my account',
    'landing_no_password_needed' => 'You will choose your password right after.',

    // Second step: choose a password
    'password_title' => 'Choose your password',
    'password_intro' => 'Your access is ready. Set a password so you can sign back in later.',
    'password_label' => 'Password',
    'password_confirm_label' => 'Confirm password',
    'password_cta' => 'Save and continue',
    'password_set' => 'Your password is saved. Welcome!',

    // Redirects after an accept attempt
    'welcome' => 'Welcome to :organization.',
    'flash_already_accepted' => 'This invitation has already been used. Sign in with your email address.',
    'flash_expired' => 'This invitation link has expired.',
    'flash_revoked' => 'This invitation link has been revoked.',
    'flash_sandbox_forbidden' => 'This organization does not accept direct invitations.',
    'flash_email_used_elsewhere' => 'This address is already associated with another BouclePro account.',
    'flash_invalid' => 'This invitation link is not valid.',

    // "Test host" (local/testing only)
    'host_label' => 'Test host',
    'host_help' => 'Optional — generates invitation links pointing at a local test tunnel.',
    'host_placeholder' => 'https://example.trycloudflare.com',
    'host_invalid' => 'Provide a base URL only (https://host), with no path, query, fragment or credentials.',
    'host_not_allowed_here' => 'The test host is not available in this environment.',
    'host_badge' => 'Test host',

    // SuperAdmin surface
    'admin_sandbox_forbidden' => 'This organization is a demonstration world: no real invitation is sent to it.',
    'admin_resent' => 'Invitation resent.',
    'admin_resend_impossible' => 'This invitation can no longer be resent.',
    'admin_revoked' => 'Invitation revoked.',
    'admin_revoke_impossible' => 'Only a pending invitation can be revoked.',

];
