<?php

return [
    'meta_title' => 'À propos de BouclePro',

    // Navigation sidebar
    'nav_besoin' => 'Besoin',
    'nav_mission' => 'Mission',
    'nav_boucle' => 'Boucle',
    'nav_transmission' => 'Transmission',
    'nav_memoire' => 'Mémoire',
    'nav_personnes' => 'Personnes',
    'nav_positionnement' => 'Positionnement',
    'nav_tableau' => 'Tableau',
    'nav_cta' => 'Commencer',

    // Écran 1 — Besoin
    's1_kicker' => 'Besoin',
    's1_title' => 'Relier les bonnes personnes, au bon moment.',
    's1_text' => "L'intelligence collective ne naît pas d'un flux, mais d'une rencontre : quelques personnes réunies autour d'un même sujet.",
    's1_support' => "Un besoin, une compétence, une intuition — et l'échange devient action.",

    // Écran 2 — La mission
    's2_kicker' => 'La mission',
    's2_title' => "D'un besoin flou à une mission claire.",
    's2_text' => "Vous formulez votre besoin en langage naturel. L'IA le met au net — objectif, contexte, savoir-faire requis — et BouclePro vous oriente vers celles et ceux qui peuvent vraiment aider.",
    's2_support' => 'La compétence juste se trouve en quelques minutes, là où il fallait des jours.',
    's2_cycle' => ['Mission', 'Boucle', 'Résultat', 'Mémoire'],

    // Écran 3 — La boucle
    's3_kicker' => 'La boucle, concrètement',
    's3_title' => 'Quelques personnes. Pas une foule.',
    's3_text' => "Une boucle rassemble dix membres au plus, autour d'une mission précise. Assez peu pour que chacun compte, assez pour qu'une situation se débloque : demander de l'aide, transmettre un savoir-faire, progresser, rebondir, coordonner.",
    'loop_types' => ['Aide', 'Transmission', 'Progression', 'Rebond', 'Coordination'],

    // Écran 4 — Transmission & compagnonnage
    's4_kicker' => 'Transmission & compagnonnage',
    's4_title' => "On apprend en avançant avec d'autres.",
    's4_text' => "Une pédagogie par l'entraide : le savoir circule quand le besoin émerge, de pair à pair, dans le flux du travail.",
    's4_support' => "L'IA peut accompagner cet apprentissage — un compagnonnage assisté — sans jamais se substituer à la relation humaine.",

    // Écran 5 — Mémoire collective
    's5_kicker' => 'Mémoire collective',
    's5_title' => 'Le fil oublie. La boucle se souvient.',
    's5_text' => "Le fruit d'une mission — réponse, décision, livrable — se dépose dans un journal vivant. Rien à relire : l'essentiel reste daté, retrouvable, réutilisable.",
    's5_support' => "Une organisation cesse alors de perdre ce qu'elle apprend. L'IA synthétise et tient le contexte ; la décision, elle, demeure humaine.",

    // Écran 6 — Les personnes
    's6_kicker' => 'Les personnes',
    's6_title' => 'Pas un profil figé. Un signal vivant.',
    's6_text' => "Chacun dit ce qui l'anime aujourd'hui : ce qu'il cherche, ce qu'il peut transmettre, ou ces deux personnes qu'il faudrait relier.",
    's6_punch' => "C'est de ces signaux brefs, lancés sans rien attendre en retour, que naissent les rencontres improbables.",

    // Écran 7 — Positionnement + tableau comparatif
    's7_kicker' => 'Positionnement',
    's7_title' => "Ce n'est pas un réseau social de plus.",
    's7_punch' => 'Là où les plateformes classiques optimisent la visibilité, BouclePro cultive la coopération.',
    's7_text' => "Rien ici n'est pensé pour publier, capter l'attention ou collectionner des contacts. Tout est pensé pour créer de l'appartenance, faire circuler l'entraide, apprendre par la pratique et bâtir une mémoire commune — code ouvert, données souveraines.",
    's4_compare_headers' => ['Usage', 'LinkedIn', 'Reddit', 'Discord', 'BouclePro'],
    's4_compare_rows' => [
        ['label' => 'Gagner en visibilité', 'linkedin' => '✓', 'reddit' => '△', 'discord' => '△', 'bouclepro' => '△'],
        ['label' => "Discuter autour d'un sujet", 'linkedin' => '△', 'reddit' => '✓', 'discord' => '✓', 'bouclepro' => '✓'],
        ['label' => 'Créer un espace de confiance', 'linkedin' => '△', 'reddit' => '△', 'discord' => '✓', 'bouclepro' => '✓'],
        ['label' => "Demander ou proposer de l'aide", 'linkedin' => '△', 'reddit' => '△', 'discord' => '△', 'bouclepro' => '✓'],
        ['label' => 'Apprendre au bon moment', 'linkedin' => '✕', 'reddit' => '△', 'discord' => '△', 'bouclepro' => '✓'],
        ['label' => "Transformer l'échange en résultat", 'linkedin' => '✕', 'reddit' => '△', 'discord' => '△', 'bouclepro' => '✓'],
        ['label' => 'Capitaliser la mémoire collective', 'linkedin' => '✕', 'reddit' => '△', 'discord' => '✕', 'bouclepro' => '✓'],
        ['label' => 'Maîtriser son code et ses données', 'linkedin' => '✕', 'reddit' => '✕', 'discord' => '✕', 'bouclepro' => '✓'],
    ],
    's4_legend' => '✓ adapté · △ possible · ✕ non central · code ouvert, données souveraines',

    // Écran 8 — Tableau comparatif
    's8_kicker' => 'Comparatif',
    's8_title' => 'En un coup d\'œil.',

    // Écran 9 — Commencer
    's9_kicker' => 'Commencer',
    's9_title' => 'Commencer par une boucle.',
    's9_text' => 'Un besoin, un sujet, quelques personnes : un espace pour réfléchir, décider et agir.',
    's9_support' => "BouclePro transforme une relation en coopération, et une coopération en mémoire partagée. C'est ainsi que des personnes s'élancent les unes les autres vers ce qu'elles n'auraient pas atteint seules.",

    'cta_primary' => 'Créer une organisation',
    'cta_secondary' => 'Commencer une boucle',
];
