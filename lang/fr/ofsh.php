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
    'meta_description' => 'Professionnels de la filière : partageons nos expériences, soutenons-nous face aux difficultés et faisons avancer nos projets ensemble.',

    'nav_about' => 'À propos',
    'nav_login' => 'Se connecter',
    'nav_dashboard' => 'Tableau de bord',

    'eyebrow' => 'Organismes de formation solidaires et humanistes',

    'title_1' => 'La solidarité',
    'title_2' => 'nous rassemble.',
    'title_3' => 'L’entraide',
    'title_4' => 'nous fait avancer.',

    'intro' => 'Professionnels de la filière : partageons nos expériences, soutenons-nous face aux difficultés et faisons avancer nos projets ensemble.',

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

    /*
     * TASK-1700 — la page « À propos ».
     *
     * Le texte est de Cyril, repris de la TASK : les huit titres sont cités
     * mot pour mot, et les corps développent les points qu'elle nomme. Rien
     * n'est inventé — pas de chiffre, pas de date de création, pas de nombre
     * de membres. La TASK l'interdit explicitement (« éviter les statistiques
     * inventées »), et le produit n'a aucune de ces données à lire.
     */

    // La navigation par ancres : huit rubriques FRANÇAISES, une par section.
    'about_nav_1' => 'Collectif',
    'about_nav_2' => 'Raison d’être',
    'about_nav_3' => 'Valeurs',
    'about_nav_4' => 'Participer',
    'about_nav_5' => 'Boucles',
    'about_nav_6' => 'Échanges',
    'about_nav_7' => 'IA',
    'about_nav_8' => 'Rejoindre',

    'about_meta_title' => 'À propos',

    // 1. Introduction
    'about_s1_kicker' => 'Le collectif',
    'about_s1_title' => 'Ensemble, donnons du sens à la solidarité professionnelle.',
    'about_s1_p1' => 'OFSH rassemble des formateurs et des organismes de formation qui partagent une conviction : nous avons davantage à gagner à nous entraider qu’à avancer chacun de notre côté.',
    'about_s1_p2' => 'Nous croyons à la force du collectif, au partage des expériences et à la coopération entre professionnels.',
    'about_s1_punch' => 'Parce que derrière chaque organisme, chaque projet et chaque difficulté, il y a avant tout des personnes.',

    // 2. Notre raison d'être
    'about_s2_kicker' => 'Notre raison d’être',
    'about_s2_title' => 'Personne ne devrait rester seul face aux difficultés de son métier.',
    'about_s2_p1' => 'Exercer dans la formation, c’est avancer sur plusieurs terrains à la fois. Chacun de nous y a déjà buté, et le plus souvent seul.',
    'about_s2_p2' => 'Ces questions ont pourtant déjà été rencontrées par d’autres. Les réponses existent, dispersées dans les expériences des uns et des autres : c’est cette richesse-là qui devient collective lorsqu’on la met en commun.',
    'about_s2_item_1_title' => 'Complexité administrative',
    'about_s2_item_1_text' => 'Des obligations réglementaires qui évoluent, et peu de temps pour les suivre.',
    'about_s2_item_2_title' => 'Qualiopi et certification',
    'about_s2_item_2_text' => 'Des exigences exigeantes, où l’expérience d’un pair vaut souvent mieux qu’un guide.',
    'about_s2_item_3_title' => 'Financement',
    'about_s2_item_3_text' => 'Des circuits mouvants, qu’on apprend rarement ailleurs que sur le terrain.',
    'about_s2_item_4_title' => 'Isolement',
    'about_s2_item_4_text' => 'Travailler en indépendant, c’est souvent décider sans personne à qui en parler.',
    'about_s2_item_5_title' => 'Pratiques pédagogiques',
    'about_s2_item_5_text' => 'Des approches qui se renouvellent, et qui gagnent à être confrontées.',
    'about_s2_item_6_title' => 'Numérique et IA',
    'about_s2_item_6_text' => 'Des outils nouveaux, qu’il vaut mieux explorer à plusieurs que subir seul.',

    // 3. Nos valeurs
    'about_s3_kicker' => 'Nos valeurs',
    'about_s3_title' => 'Solidaires par conviction. Humanistes par engagement.',
    'about_s3_v1_title' => 'Solidarité',
    'about_s3_v1_text' => 'S’entraider lorsque l’un de nous rencontre une difficulté.',
    'about_s3_v2_title' => 'Humanisme',
    'about_s3_v2_text' => 'Placer les personnes, leur dignité et leur diversité au cœur de nos relations.',
    'about_s3_v3_title' => 'Partage',
    'about_s3_v3_text' => 'Transmettre nos connaissances et expériences sans chercher systématiquement une contrepartie.',
    'about_s3_v4_title' => 'Coopération',
    'about_s3_v4_text' => 'Unir nos compétences pour trouver des solutions et faire émerger des initiatives.',

    // 4. Comment participer
    'about_s4_kicker' => 'Comment participer ?',
    'about_s4_title' => 'Chacun peut avoir besoin d’aide. Chacun a quelque chose à apporter.',
    'about_s4_lead' => 'Quatre manières d’entrer dans le collectif. Aucune n’est plus légitime qu’une autre, et on passe de l’une à l’autre au fil du temps.',
    'about_s4_i1_text' => 'Je rencontre une difficulté ou j’ai besoin d’un conseil.',
    'about_s4_i2_text' => 'Je mets mon expérience au service d’un autre membre.',
    'about_s4_i3_text' => 'Une expérience, une ressource ou une idée utile au collectif.',
    'about_s4_i4_text' => 'Je propose une rencontre, une initiative ou une action collective.',

    // 5. Les Boucles
    'about_s5_kicker' => 'La force des Boucles',
    'about_s5_title' => 'Des échanges qui deviennent des actions.',
    'about_s5_p1' => 'Une Boucle réunit des membres autour d’un sujet, d’un besoin ou d’un projet. On y pose une question, on y répond, on y range ce qui a servi — et ce qui s’y construit reste accessible à ceux qui arriveront après.',
    'about_s5_p2' => 'Ce n’est pas un fil de discussion de plus : une Boucle existe pour aboutir à quelque chose, et ce quelque chose profite à tout le collectif.',
    'about_s5_t1' => 'Entraide Qualiopi',
    'about_s5_t2' => 'Réglementation de la formation',
    'about_s5_t3' => 'Pratiques pédagogiques',
    'about_s5_t4' => 'Outils numériques et IA',
    'about_s5_t5' => 'Projets communs',
    'about_s5_t6' => 'Rencontres et partage d’expériences',

    // 6. Une autre manière de communiquer
    'about_s6_kicker' => 'Une autre manière de communiquer',
    'about_s6_title' => 'Moins de bruit. Plus d’entraide.',
    'about_s6_p1' => 'Nous cherchons des échanges constructifs, accessibles et respectueux du temps de chacun. Un message utile vaut mieux que dix notifications.',
    'about_s6_l1' => 'La qualité des échanges plutôt que leur quantité.',
    'about_s6_l2' => 'Le respect des membres et de leurs différences.',
    'about_s6_l3' => 'La possibilité de participer à son rythme.',
    'about_s6_l4' => 'Des rencontres humaines et des échanges directs.',
    'about_s6_l5' => 'Des ressources utiles conservées et transmises.',

    // 7. La technologie
    'about_s7_kicker' => 'Une technologie au service de l’humain',
    'about_s7_title' => 'L’IA facilite les échanges. L’entraide reste humaine.',
    'about_s7_p1' => 'BouclePro met à disposition d’OFSH des outils de coopération assistés par l’IA. Elle peut aider à clarifier une demande, reformuler un message, organiser des informations et faciliter la transmission des connaissances.',
    'about_s7_p2' => 'Elle ne remplace ni l’expérience des membres, ni leur discernement, ni les relations humaines.',
    'about_s7_rule' => 'Aucune contribution n’est publiée automatiquement sans validation humaine.',

    // 8. Rejoindre
    'about_s8_kicker' => 'Rejoindre le collectif',
    'about_s8_title' => 'Et si nous avancions ensemble ?',
    'about_s8_p1' => 'Vous êtes formateur, responsable d’un organisme de formation ou professionnel de la formation ?',
    'about_s8_p2' => 'Vous partagez nos valeurs de solidarité, de respect et d’entraide ?',
    'about_s8_p3' => 'Alors vous avez votre place dans cette dynamique collective.',
    'about_s8_punch' => 'Venez partager vos expériences, poser vos questions, apporter votre aide et construire avec nous un collectif plus humain.',
    'about_cta_join' => 'Rejoindre OFSH',
    'about_cta_loops' => 'Découvrir les Boucles',

    // Le pied et les reperes d'accessibilite de la barre de navigation.
    // Ils vivent dans CE groupe plutot que dans `footer`/`common` : le
    // premier ne porte pas de credit BouclePro, le second n'existe pas —
    // `hero-v2` appelle `common.open_menu` et affiche la clef nue.
    'powered_by' => 'Propulsé par BouclePro',
    'open_menu' => 'Ouvrir le menu',
    'nav_menu' => 'Navigation principale',
];
