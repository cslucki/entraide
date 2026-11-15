<?php

return [
    // TASK-1755 §9.6 — le profil comme bilan d'entraide, pas comme solde.
    'bilan' => [
        'personnes_aidees' => '{0}person helped|{1}person helped|[2,*]people helped',
        'fois_aide' => '{0}times helped|{1}time helped|[2,*]times helped',
        'temps_offert' => 'of time offered',
        'mercis' => '{0}no thanks yet|{1}:count thank-you received|[2,*]:count thanks received',
    ],

    'back_to_directory' => 'Back to directory',
    'edit_profile' => 'Edit my profile',
    'publish_to_blog' => 'Publish to the blog',
    'available' => 'Available',
    'unavailable' => 'Unavailable',
    'deactivated_user' => 'Deactivated user',
    'exchanges' => ':count exchange|:count exchanges',
    'points' => ':count pts',
    'about' => 'About',
    'can_offer' => 'What this member can offer you',
    'skills' => 'Skills',
    'services_offered' => '{0} Proposals|{1} Proposal|[2,*] Proposals',
    'ai_agent_available' => 'AI agent available',
    'ai_agent_available_desc' => 'This member has enabled their AI agent. You can ask questions about their background, skills and availability.',
    'ai_agent_enabled' => 'AI agent enabled',
    'ai_agent_enabled_desc' => 'Visitors can interact with your profile through the AI agent.',
    'view_exchanges' => 'View exchanges',
    'agent_cta_title' => 'Profile AI agent',
    'agent_cta_hint' => 'Ask a question about what this member can offer you.',
    'start' => 'Start',
    'write_to' => 'Write to :name',
    'start_conversation' => 'Start a direct human conversation.',
    'services_count' => ':count proposal|:count proposals',
    'no_services' => 'No active services yet.',
    'help_section' => '{0} Requests|{1} Request|[2,*] Requests',
    'requests_count' => ':count request|:count requests',
    'before_deadline' => 'Before :date',
    'blog_section' => 'Blog',
    // TASK-1788 correctif 05 — le titre de la carte d'apercu, au meme
    // gabarit que « Blog » juste au-dessus.
    'stats_section' => 'Stats',
    'blog_count' => ':count article|:count articles',
    'no_posts' => "This user hasn't published any articles yet.",
    'reviews_section' => 'Reviews received',
    'report' => 'Report',
    'report_user' => 'Report this user',
    'report_reason' => 'Reason...',
    'report_abuse' => 'Abusive behavior',
    'report_scam' => 'Scam or fraud',
    'report_fake' => 'Fake profile',
    'report_other' => 'Other',
    'report_details' => 'Details (optional)...',
    'report_send' => 'Send',
    'report_cancel' => 'Cancel',
    'edit_title' => 'Edit my profile',

    // TASK-1824 — the "Account security" page.
    'security_title' => 'Account security',
    'security_intro' => 'Your password, your access, and closing your account.',
    'security_cta' => 'Account security',
    'security_back_to_profile' => 'Back to profile',
    'current_organization' => 'Active organization: :name',

    'info_title' => 'Profile information',
    'info_description' => 'Update your account information and email address.',
    'presentation_section' => 'Introduction',
    'presentation_description' => 'These details introduce your profile to other members.',
    'first_name' => 'First name',

    // TASK-1762 point 1 — the member's company, association or collective.
    //
    // NOT "Organisation": that word already names the TENANT everywhere else in
    // the English interface. Two different things under one label on the same
    // screen would be a trap.
    'structure' => 'Company or association',

    // TASK-1762 point 16 — the three sections of the reordered form.
    'identity_section' => 'Profile information',
    'identity_description' => 'Who you are, and how to reach you.',
    'locale_section' => 'Main language',
    'locale_description' => 'The language the platform speaks to you in.',
    'slug_on' => 'Your profile address on :organization',

    // TASK-1762 point 3 — the button at the top of the profile edit page.
    'view_public_profile' => 'View my public profile',

    // TASK-1762 point 16 — the presentation editor's fullscreen mode.
    'editor_fullscreen' => 'Fullscreen',
    'editor_exit_fullscreen' => 'Exit fullscreen',
    'structure_help' => 'Your company, association or collective, if you have one.',

    // TASK-1762 point 17 — the mandatory baseline, "in one sentence".
    'slogan' => 'Slogan',
    'slogan_help' => 'What defines you in one sentence.',
    'name' => 'Name',
    'email' => 'Email address',
    'phone' => 'Phone number *',
    'phone_placeholder' => 'e.g. 06 12 34 56 78',
    'phone_help' => 'Required to publish services or requests.',
    'privacy' => 'Privacy',
    'show_email' => 'Show my email on my public profile and directory',
    'show_phone' => 'Show my phone number on my public profile and directory',
    'location' => 'Location (City, Region)',
    'location_placeholder' => 'e.g. Lyon, Rhône',
    'bio' => 'About me',
    'bio_help' => 'Describe who you are, your expertise and background. This introduction appears on your public profile and reassures members before they contact you.',
    'bio_placeholder' => 'e.g. Independent HR consultant since 2015, I handle recruitment, training and management support for SMEs...',
    'bio_max' => 'Max 500 characters',
    'bio_remaining' => ':count remaining',
    'city' => 'City',
    'country' => 'Country',
    'country_placeholder' => 'Select a country',
    'preferred_locale' => 'Primary language',
    'preferred_locale_placeholder' => 'Select a language',
    'locale_fr' => 'French',
    'locale_en' => 'English',
    'membership' => 'Membership',
    'private_billing_section' => 'Private / billing information',
    'private_billing_description' => 'These details stay private and are not shown on your public profile.',
    'address_line1' => 'Address',
    'address_line2' => 'Address line 2',
    'postal_code' => 'Postal code',
    'website' => 'Website',
    'linkedin' => 'LinkedIn profile',

    // TASK-1750 — "Other links": several typed URLs, below the two fields
    // above.
    'links_heading' => 'Other links',
    'links_help' => 'Add your other addresses. They will appear on your public profile.',
    'links_add' => 'Add a link',
    'links_remove' => 'Remove this link',
    'link_type_instagram' => 'Instagram',
    // TASK-1762 point 18 — Cyril's request: the network's name changed.
    'link_type_twitter' => 'X (formerly Twitter)',
    'link_type_facebook' => 'Facebook',
    'link_type_website' => 'Website',
    'link_type_blog' => 'Blog',
    'link_type_app' => 'App',
    'avatar' => 'Avatar',
    'avatar_help' => 'JPG, PNG or GIF — max 2 MB',
    // TASK-1749 — the "change my photo" window, opened by the pencil on the
    // avatar of the public profile.
    'avatar_change' => 'Change my photo',
    'avatar_modal_help' => 'It appears on your profile, your messages and your Loops.',
    'avatar_drop' => 'Drop a photo here, or click to choose one',
    'avatar_save' => 'Save photo',
    'avatar_updated' => 'Your photo has been updated.',
    // TASK-1788 correctif 02 — REMOVING the photo. Until now it could only be
    // replaced, never removed: a null avatar falls back to generated initials.
    'avatar_remove' => 'Remove my photo',
    'avatar_remove_cancel' => 'Cancel removal',
    'avatar_remove_pending' => 'Your photo will be removed when you save. Your initials will replace it.',
    'avatar_remove_confirm' => 'Remove photo',
    'avatar_removed' => 'Your photo has been removed.',
    'available_label' => 'I am available for exchanges',
    'save' => 'Save',
    'saved' => 'Saved.',
    'cancel' => 'Cancel',

    'password_section_heading' => 'Update password',
    'password_section_description' => 'Use a long, random password to keep your account secure.',
    'current_password' => 'Current password',
    'new_password' => 'New password',
    'confirm_password' => 'Confirm password',

    // TASK-1825 — premium confirmation after a successful password change.
    'password_updated_success' => 'Your password has been changed successfully.',
    'password_updated_success_detail' => 'Your new password is now active for this account.',

    // TASK-1716 — see the French file: the wording moves from "delete" to
    // "close" because that is what actually happens.
    'close_account' => 'Close my account',
    'close_account_warning' => 'Closing your account deactivates it immediately: you are signed out, your access is revoked and your profile is no longer visible. Your contributions and history are kept. Only an administrator can reopen a closed account.',
    'close_account_confirm' => 'Are you sure you want to close your account?',
    'close_account_instructions' => 'Enter your password to confirm that you want to close your account.',
    'close_account_password' => 'Password',
    'close_account_submit' => 'Close my account',
    'close_account_erasure' => 'Also request the erasure of my personal data',
    'close_account_erasure_help' => 'An administrator will review your request. Some data cannot be erased when it involves someone else — an exchange, a vote or a points entry, for instance.',
    'close_account_done' => 'Your account is closed. Your access has been revoked.',

    'profile_reminder_title' => 'Complete your profile before publishing.',
    'profile_reminder_body' => 'The :fields fields are required — members need to know who they are dealing with before exchanging.',
    'reminder_fields' => 'First name, Last name, Phone, City, Country and Introduction',
    // TASK-1693 bug Z — the public profile address.
    'slug' => 'Your profile address',
    'slug_prefix' => '…/profile/',

    // TASK-1762 point 4 bis — the address in use TODAY, right of the label.
    'slug_current' => 'Currently:',
    'slug_help' => 'Pick an address that represents you, for example :exemple or :exemple_court',
    'slug_taken' => 'That address is already taken in your Organization. Please pick another one.',
    // TASK-1762 point 4 — the format rule moved out of the permanent hint and
    // into this message, the only moment it is of any use. LENGTH comes with
    // it: a two-letter address would otherwise be refused without a reason.
    'slug_invalid' => 'That address cannot be used: lowercase letters, digits and hyphens only, between 3 and 60 characters, and some words are reserved.',
    // TASK-1697 — les deux cards ajoutees a la fiche profil
    'loops_section' => 'Loops',
    'loops_count' => ':count Loop|:count Loops',
    'comments_section' => 'Latest comments',
    'comments_count' => ':count comment|:count comments',
    'comments_read_article' => 'Read the article',

    // TASK-1734 — the profile "Account security" card.
    'account_access_title' => 'Account security',
    'account_access_intro' => 'To move between organizations, use "My organizations" in your avatar menu. Advanced paths — linking two accounts with different addresses, switching to an unlisted account, removing an access — live here.',
];
