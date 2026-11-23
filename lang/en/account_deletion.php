<?php

// TASK-1898 — public account deletion page (/suppression-de-compte), required
// by Google Play. Mirror of lang/fr/account_deletion.php: describes the REAL
// flow (immediate deactivation, erasure executed by an administrator). No
// automatic erasure is promised.

return [
    'title' => 'Delete your account and data',
    'meta_description' => 'How to delete your BouclePro account and what happens to your data.',
    'updated' => 'Version of 24 November 2026',
    'intro' => 'This page applies to bouclepro.com, to the spaces of the organisations that use it and to the BouclePro Android app, published by Association AMT.',
    'privacy_link' => 'Privacy policy',
    'legal_link' => 'Legal notice',

    'sections' => [
        [
            'title' => 'From the app or website (recommended)',
            'steps' => [
                'Sign in to BouclePro.',
                'Open your profile, then “Account security”.',
                'Under “Close my account”, tick “Also request the erasure of my personal data”, enter your password and confirm.',
            ],
            'after' => [
                'Your account is deactivated immediately: you are signed out, you can no longer sign in and your profile is no longer visible. Your erasure request is then reviewed and carried out by an administrator.',
            ],
        ],
        [
            'title' => 'Without access to your account',
            'paragraphs' => [
                'Write to the publisher, at the details given in the legal notice, from your account’s email address, with the subject “BouclePro account deletion” and the organisation you belong to. We may ask you to confirm your identity.',
            ],
            'link' => 'legal',
        ],
        [
            'title' => 'What is deleted',
            'items' => [
                'your account and profile;',
                'your Loop memberships, reactions, favourites and badges;',
                'your notification preferences, your notifications and the notification subscriptions of your devices;',
                'your conversations with the artificial-intelligence assistants;',
                'your workshop registrations.',
            ],
        ],
        [
            'title' => 'What is kept without your name',
            'paragraphs' => [
                'Your messages and comments in shared spaces, your reviews and your contributions to Loops remain visible, without your name, so that conversations stay understandable for other members.',
            ],
        ],
        [
            'title' => 'What is handed over to another member',
            'paragraphs' => [
                'Your articles, posts, help requests and offered services may be handed over to another member of your organisation, who becomes their author.',
            ],
        ],
        [
            'title' => 'What may be kept',
            'items' => [
                'technical sign-in and email-delivery logs, reports and moderation reports;',
                'files you placed in shared Folders;',
                'data we are legally required to keep, for the period required by law;',
                'database backups, for 7 days at most.',
            ],
        ],
        [
            'title' => 'What may delay deletion',
            'paragraphs' => [
                'Some data involves another person: an exchange, a vote or a points entry, for example, or an organisation-manager role. While such situations exist, erasure cannot be carried out; we will then contact you to resolve them.',
            ],
        ],
        [
            'title' => 'Timeframe',
            'paragraphs' => [
                'We handle your request within one month, subject to the exceptions and extensions provided by law.',
            ],
        ],
        [
            'title' => 'Closing without erasure',
            'paragraphs' => [
                'If you close your account without ticking the erasure request, it is only deactivated: your contributions and history are kept, and only an administrator can reopen a closed account. You can request erasure later, as described above.',
            ],
        ],
    ],
];
