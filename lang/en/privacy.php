<?php

// TASK-1898 — public privacy policy (/confidentialite), required by Google
// Play. Mirror of lang/fr/privacy.php: every sentence states a fact verified
// in the code or in the PROD twin; anything not yet decided is LEFT OUT.

return [
    'title' => 'Privacy policy',
    'meta_description' => 'What data BouclePro processes, why, with whom, and how to exercise your rights.',
    'updated' => 'Version of 24 November 2026',
    'intro' => 'BouclePro is a mutual-aid and collaboration platform used by several organisations (associations, networks, collectives). This policy explains what data we process, why, with whom, and how to exercise your rights. It applies to bouclepro.com, to the spaces of the organisations that use it and to the BouclePro Android app, which displays the same service.',
    'deletion_link' => 'Delete my account and data',
    'legal_link' => 'Legal notice',

    'sections' => [
        [
            'title' => '1. Who publishes BouclePro',
            'paragraphs' => [
                'BouclePro is published by Association AMT, in Marseille (France). Its full contact details are given in the legal notice. For any question about your personal data, write to the publisher at those details.',
            ],
            'link' => 'legal',
        ],
        [
            'title' => '2. Data we process',
            'items' => [
                'Your account: last name, first name, email address and password (stored in an irreversible encrypted form).',
                'Your profile, depending on what you choose to add: organisation, tagline, bio, photo, city, postcode, country, phone, website, links. You decide whether your email and phone are visible to other members.',
                'What you publish: messages, voice messages, articles, posts, comments, help requests and offers, files placed in Folders, reviews.',
                'Your memberships and settings: organisations and Loops you belong to, language, notification preferences.',
                'Technical data: IP address and browser type, recorded when you sign in and to manage your session; the reports you send us.',
                'If you enable notifications: a technical address specific to your device, provided by your browser, used to send them to you.',
            ],
            'after' => [
                'We do not collect your location, your contacts or any payment data.',
            ],
        ],
        [
            'title' => '3. Why we use it',
            'items' => [
                'To provide the service: create and secure your account, show your profile to members of your organisations, deliver your messages and files (performance of the service you use).',
                'To notify you, by email or push notification, about activity that concerns you, according to your settings (performance of the service; you can change them in your notification settings).',
                'Security: protect accounts, detect unusual sign-ins, prevent abuse (legitimate interest).',
                'To offer artificial-intelligence features, described below.',
            ],
            'after' => [
                'Your data is never sold or used for advertising.',
            ],
        ],
        [
            'title' => '4. Artificial intelligence',
            'paragraphs' => [
                'Some optional features, when your organisation has enabled them, rely on artificial-intelligence models: assistant in Loop discussions, article-writing help, search and summaries across the documents of a Folder, help phrasing a help request, profile assistant, answers to questions asked from the home page, summary of a Loop’s knowledge.',
                'The relevant text (and, for Folders, excerpts of the documents) is then sent to our AI providers, OpenAI and OpenRouter, to produce the answer. AI assists members; it never makes decisions for them.',
            ],
        ],
        [
            'title' => '5. Who can see your data',
            'paragraphs' => [
                'Your data is visible to members of the organisations you belong to, according to each space’s rules (public or private Loop, shared Folder…). One organisation’s data cannot be accessed from another.',
                'We use the following technical providers, solely to run the service:',
            ],
            'items' => [
                'Laravel Cloud: application and file hosting;',
                'Neon: database;',
                'Cloudflare: traffic delivery and protection;',
                'an email relay provider: sending emails;',
                'your browser’s or device’s notification service (for example Google Firebase Cloud Messaging on Android): delivering notifications, whose content is encrypted and unreadable to it;',
                'OpenAI and OpenRouter: artificial-intelligence features;',
                'resource delivery networks (Bunny Fonts for fonts, jsDelivr and cdnjs for some libraries and icons), which receive your device’s IP address when a page loads them.',
            ],
        ],
        [
            'title' => '6. Transfers outside the European Union',
            'paragraphs' => [
                'The application and its files are hosted in the United States, where our artificial-intelligence providers are also established. Your data is therefore transferred and processed outside the European Union.',
            ],
        ],
        [
            'title' => '7. How long we keep it',
            'items' => [
                'Voice messages: deleted automatically 5 days after they are sent.',
                'Your account and content: kept while your account is open. After closure, see the “Delete my account and data” page.',
                'Backups: the database can be restored over the last 7 days, so erased data leaves the database backups within 7 days at most.',
            ],
            'link' => 'deletion',
        ],
        [
            'title' => '8. Cookies',
            'paragraphs' => [
                'We only use cookies that are necessary for the service: keeping you signed in (session, and the “Stay signed in on this device” option, valid for up to 400 days), form protection, recognising a visitor on an organisation’s public page, and remembering that you declined the app-install prompt. No advertising or analytics cookies.',
            ],
        ],
        [
            'title' => '9. Your rights',
            'paragraphs' => [
                'You may access your data, correct it (mostly from your profile), request its erasure or portability, request the restriction of its processing and object to processing based on legitimate interest.',
                'To exercise these rights, write to the publisher at the details given in the legal notice. We reply within one month, subject to the exceptions and extensions provided by law. You may also lodge a complaint with the CNIL (www.cnil.fr) or your local supervisory authority.',
            ],
            'link' => 'legal',
        ],
        [
            'title' => '10. Security',
            'paragraphs' => [
                'Encrypted connections (HTTPS), irreversibly encrypted passwords, encrypted notification content, data separated between organisations.',
            ],
        ],
        [
            'title' => '11. Changes',
            'paragraphs' => [
                'Any change to this policy will be published on this page, with its date.',
            ],
        ],
    ],
];
