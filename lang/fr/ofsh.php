<?php

/*
 * TASK-1699 — les textes du gabarit d'accueil OFSH.
 *
 * Un groupe DEDIE, et pas des clefs ajoutees a `hero.php` : `hero` sert le
 * gabarit `bouclepro_hero_v2`, dont la structure n'est pas celle-ci (trois mots
 * empiles contre quatre lignes de titre, quatre cartes d'orbite aux libelles
 * differents, pas de mantra, pas de section « A propos »). Les melanger aurait
 * fait porter a chaque gabarit les clefs de l'autre.
 *
 * Ces textes sont lus par `org_trans()` : une Organization peut donc les
 * surcharger depuis son ecran de traductions, sans toucher ce fichier.
 *
 * Source : design/HomePage/OFSH-Homepage-Couleurs/laravel/lang/fr/ofsh.php,
 * maquette validee par Cyril le 25/10.
 */

return [
    'meta_title' => 'La solidarité nous rassemble',
    'meta_description' => 'Formateurs et organismes de formation : partageons nos expériences, soutenons-nous face aux difficultés et faisons avancer nos projets ensemble.',

    'nav_about' => 'À propos',
    'nav_login' => 'Se connecter',
    'nav_dashboard' => 'Tableau de bord',

    'eyebrow' => 'Organismes de formation solidaires et humanistes',

    'title_1' => 'La solidarité',
    'title_2' => 'nous rassemble.',
    'title_3' => 'L’entraide',
    'title_4' => 'nous fait avancer.',

    'intro' => 'Formateurs et organismes de formation : partageons nos expériences, soutenons-nous face aux difficultés et faisons avancer nos projets ensemble.',

    'mantra_1' => 'Écoutons.',
    'mantra_2' => 'Partageons.',
    'mantra_3' => 'Agissons ensemble.',

    'cta_join' => 'Rejoindre le collectif',
    'cta_about' => 'Découvrir OFSH',

    'card_help_title' => 'J’ai besoin d’entraide',
    'card_help_desc' => 'Un conseil, une question, une difficulté.',
    'card_offer_title' => 'Je peux apporter mon aide',
    'card_offer_desc' => 'Mon expérience peut servir à quelqu’un.',
    'card_share_title' => 'J’ai quelque chose à partager',
    'card_share_desc' => 'Une ressource ou un retour d’expérience.',
    'card_together_title' => 'Et si on faisait ensemble ?',
    'card_together_desc' => 'Une rencontre, un projet, une initiative.',

    'ai_note' => 'L’IA facilite les échanges. L’entraide reste humaine.',

    'about_title' => 'Un collectif pour ne pas avancer seul.',
    'about_body' => 'OFSH rassemble des professionnels de la formation qui choisissent la coopération plutôt que l’isolement. Ici, on peut poser une question, écouter un pair, transmettre un savoir-faire ou construire ensemble une action utile. Les échanges prennent tout leur sens lorsqu’ils deviennent de l’entraide concrète.',
    'about_line' => 'Ici, chacun peut avoir besoin d’aide. Et chacun a quelque chose à apporter.',

    'logo_alt' => 'OFSH',

    // Le pied et les reperes d'accessibilite de la barre de navigation.
    // Ils vivent dans CE groupe plutot que dans `footer`/`common` : le
    // premier ne porte pas de credit BouclePro, le second n'existe pas —
    // `hero-v2` appelle `common.open_menu` et affiche la clef nue.
    'powered_by' => 'Propulsé par BouclePro',
    'open_menu' => 'Ouvrir le menu',
    'nav_menu' => 'Navigation principale',
];
