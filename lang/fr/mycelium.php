<?php

/**
 * TASK-1349 — vocabulaire du Mycelium et de la transparence publique.
 *
 * INVARIANT DE CETTE TASK : en francais, on ecrit « organisation ». Jamais
 * « Organization », qui est le nom du MODELE Laravel et n'a aucune raison
 * d'apparaitre sous les yeux d'un utilisateur francophone. Un test verrouille
 * ce point sur les surfaces T1349.
 */
return [
    'title' => 'Mycélium BouclePro',
    'subtitle' => 'Constitution racine de la plateforme',
    'intro' => 'Ces principes gouvernent l\'IA de BouclePro. Ils s\'appliquent à toutes les organisations, et prévalent sur les principes que chacune peut ajouter.',

    'root_version' => 'Version :version',
    'root_version_seed' => 'Texte de référence',
    'root_activated_at' => 'En vigueur depuis le :date',

    'inheritance_title' => 'Comment fonctionne l\'héritage',
    'inheritance_body' => 'Chaque organisation hérite du Mycélium. Elle peut y ajouter ses propres principes, jamais les assouplir ni les contredire. Les garanties de sécurité — périmètre, confidentialité, validation humaine — sont appliquées par le code et ne dépendent d\'aucun texte.',

    'organizations_title' => 'Organisations publiques',
    'organizations_intro' => 'Ces organisations ont choisi de rendre leur Constitution publique.',
    'organizations_empty' => 'Aucune organisation n\'a encore choisi de publier sa Constitution.',
    'organizations_note' => 'Seules les organisations ayant explicitement choisi de publier apparaissent ici.',

    'tree_label' => 'Arbre de gouvernance IA',
    'tree_root' => 'Mycélium',
    'tree_open_root' => 'Voir la Constitution racine',
    'tree_open_organization' => 'Voir la Constitution de :name',

    'org_title' => 'Constitution de :name',
    'org_subtitle' => 'Constitution de l\'organisation',
    'org_version' => 'Version :version',
    'org_expand' => 'Lire',
    'org_collapse' => 'Replier',
    'org_open_page' => 'Constitution',
    'org_visit_site' => "Accueil de l'organisation",
    'org_activated_at' => 'En vigueur depuis le :date',
    'org_inherits' => 'Cette organisation hérite du Mycélium BouclePro.',
    'org_back_to_mycelium' => 'Retour au Mycélium',

    'footer_link' => 'Mycélium & organisations',

    'admin_org_title' => 'Constitution de l\'organisation',
    'admin_org_subtitle' => 'Qui sommes-nous, et quels principes fondamentaux gouvernent notre IA ?',
    'admin_org_inherited_title' => 'Mycélium BouclePro — hérité',
    'admin_org_inherited_help' => 'Appliqué à votre organisation, en lecture seule ici.',
    'admin_org_doctrine_note' => 'La Doctrine — comment l\'IA doit se comporter dans votre métier — reste dans l\'écran Comportement IA.',
    'admin_org_doctrine_link' => 'Ouvrir Comportement IA',
    'admin_org_link_from_cockpit' => 'Ouvrir la page Constitution',

    'publication_title' => 'Publication',
    'publication_help' => 'Rendre votre Constitution publique la rend lisible par tous, sans authentification, et fait apparaître votre organisation dans le Mycélium. Votre Doctrine, vos réglages et vos données restent privés.',
    'publication_public' => 'Rendre ma Constitution publique',
    'publication_private' => 'Garder ma Constitution privée',
    'publication_state_public' => 'Publique',
    'publication_state_private' => 'Privée',
    'publication_enabled' => 'Votre Constitution est désormais publique.',
    'publication_disabled' => 'Votre Constitution est redevenue privée.',
    'publication_needs_text' => 'Publiez d\'abord une Constitution : il n\'y a rien à rendre public pour l\'instant.',
];
