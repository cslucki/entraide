<?php

/*
 * TASK-1699 — the OFSH landing template strings. See `lang/fr/ofsh.php` for
 * why this is a dedicated group rather than keys added to `hero.php`.
 *
 * Source: design/HomePage/OFSH-Homepage-Couleurs/laravel/lang/en/ofsh.php.
 */

return [
    'meta_title' => 'Solidarity brings us together',
    'meta_description' => 'Trainers and training organizations: share experiences, support each other through challenges and move forward together.',

    'nav_about' => 'About',
    'nav_login' => 'Sign in',
    'nav_dashboard' => 'Dashboard',

    'eyebrow' => 'Solidarity and humanism in professional training',

    'title_1' => 'Solidarity',
    'title_2' => 'brings us together.',
    'title_3' => 'Mutual support',
    'title_4' => 'moves us forward.',

    'intro' => 'Trainers and training organizations: share experiences, support each other through challenges and move forward together.',

    'mantra_1' => 'Listen.',
    'mantra_2' => 'Share.',
    'mantra_3' => 'Act together.',

    'cta_join' => 'Join the collective',
    'cta_about' => 'Discover OFSH',

    'card_help_title' => 'I need support',
    'card_help_desc' => 'A question, advice or a challenge.',
    'card_offer_title' => 'I can help',
    'card_offer_desc' => 'My experience could help a peer.',
    'card_share_title' => 'I have something to share',
    'card_share_desc' => 'A resource, an insight or an experience.',
    'card_together_title' => 'What if we did it together?',
    'card_together_desc' => 'A meeting, project or collective action.',

    'ai_note' => 'AI makes exchange easier. Mutual aid remains human.',

    'about_title' => 'A collective where no one has to go it alone.',
    'about_body' => 'OFSH brings together training professionals who choose cooperation over isolation. Ask a question, listen to a peer, share expertise or create something together. Conversations matter when they become real support.',
    'about_line' => 'Everyone needs support sometimes. Everyone has something to contribute.',

    'logo_alt' => 'OFSH',

    // Le pied et les reperes d'accessibilite de la barre de navigation.
    // Ils vivent dans CE groupe plutot que dans `footer`/`common` : le
    // premier ne porte pas de credit BouclePro, le second n'existe pas —
    // `hero-v2` appelle `common.open_menu` et affiche la clef nue.
    'powered_by' => 'Powered by BouclePro',
    'open_menu' => 'Open menu',
    'nav_menu' => 'Main navigation',
];
