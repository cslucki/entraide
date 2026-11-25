<?php

// TASK-1903 — page publique /securite-enfants : normes de BouclePro contre
// l'exploitation et les abus sexuels sur mineurs (CSAE), exigees par Google
// Play (« Child Safety Standards ») pour les applications de reseaux sociaux.
//
// Le texte ne decrit QUE ce qui existe : un examen humain (aucune detection ni
// alerte automatique), le lien « Signaler » sur les seuls profils, offres et
// demandes (pas encore sur le Flux, les Boucles, les messages ni les articles),
// le retrait de contenu et la suspension de compte par l'equipe, la
// transmission manuelle des cas confirmes a PHAROS. Aucun delai garanti,
// aucune duree de conservation inventee. A mettre a jour apres S2a / S2b.
// Les `id` sont des ancres stables, identiques en anglais.

return [
    'title' => 'Sécurité des enfants',
    'heading' => 'Nos normes contre l’exploitation et les abus sexuels sur mineurs',
    'updated' => 'Version du 25 novembre 2026',
    'intro' => 'Ces normes s’appliquent à BouclePro, plateforme d’entraide éditée par l’Association AMT : le site bouclepro.com, les espaces des organisations qui l’utilisent et l’application Android BouclePro.',
    'toc_title' => 'Sur cette page',
    'report_title' => 'Signaler un contenu ou un comportement',
    'report_text' => 'Écrivez au contact désigné pour la sécurité des enfants :',
    'contact_email' => 'cslucki@gmail.com',
    'contact_link' => 'Écrire à cslucki@gmail.com',
    'legal_link' => 'Mentions légales',
    'privacy_link' => 'Politique de confidentialité',

    'sections' => [
        [
            'id' => 'interdiction',
            'title' => 'Tolérance zéro',
            'paragraphs' => [
                'BouclePro interdit strictement toute forme d’exploitation et d’abus sexuels sur mineurs (en anglais : child sexual abuse and exploitation, CSAE).',
                'Cette interdiction s’applique à tous les contenus et à tous les échanges, publics ou privés : profils, Flux, Boucles, messages privés, articles, commentaires, fichiers, offres et demandes d’aide.',
                'Toute violation entraîne le retrait des contenus, la fermeture du compte et, pour les cas confirmés, la transmission aux autorités compétentes.',
            ],
        ],
        [
            'id' => 'comportements-interdits',
            'title' => 'Ce qui est interdit',
            'items' => [
                'publier, partager, stocker, demander ou diffuser un contenu d’abus sexuel sur mineur (CSAM) : image, vidéo, texte, dessin, y compris un contenu modifié ou généré par ordinateur ou par intelligence artificielle ;',
                'solliciter un mineur ou chercher à entrer en contact avec lui à des fins sexuelles (pédopiégeage, ou « grooming ») ;',
                'menacer ou faire chanter un mineur pour obtenir des images ou des actes sexuels (sextorsion) ;',
                'sexualiser un mineur, par des propos, des commentaires ou des images ;',
                'partager des liens vers de tels contenus ou expliquer comment se les procurer ;',
                'utiliser un espace de BouclePro pour faciliter l’un de ces actes.',
            ],
        ],
        [
            'id' => 'age-minimum',
            'title' => 'Un service réservé aux adultes',
            'paragraphs' => [
                'BouclePro est réservé aux personnes âgées de 18 ans ou plus.',
                'Si nous apprenons qu’un compte appartient à une personne mineure, nous le fermons.',
            ],
        ],
        [
            'id' => 'signaler',
            'title' => 'Comment nous signaler un problème',
            'paragraphs' => [
                'Si vous constatez un contenu ou un comportement contraire à ces normes, signalez-le-nous sans attendre :',
            ],
            'items' => [
                'par email, au contact désigné : cslucki@gmail.com. Ce moyen couvre tous les contenus de BouclePro. Indiquez si possible l’adresse de la page, le nom du compte concerné et la date ;',
                'avec le lien « Signaler » présent sur les profils, les offres et les demandes d’aide.',
            ],
            'after' => [
                'Le lien « Signaler » n’existe pas encore sur les autres contenus (Flux, messages de Boucle, messages privés, articles) : pour ceux-ci, écrivez-nous par email.',
                'Ne nous envoyez jamais de copie d’une image ou d’une vidéo illicite : décrivez-la et indiquez où elle se trouve.',
            ],
            'link' => 'contact',
        ],
        [
            'id' => 'traitement',
            'title' => 'Ce que nous faisons',
            'paragraphs' => [
                'Chaque signalement est examiné en priorité par une personne de l’équipe BouclePro. Lorsqu’une violation est confirmée :',
            ],
            'items' => [
                'nous retirons le contenu concerné ;',
                'nous suspendons ou fermons le ou les comptes impliqués ;',
                'nous transmettons les cas confirmés aux autorités compétentes, notamment, en France, à la plateforme PHAROS du ministère de l’Intérieur (internet-signalement.gouv.fr) ;',
                'nous conservons les éléments de preuve nécessaires, dans la mesure prévue par la loi, et les communiquons aux autorités qui les demandent.',
            ],
            'after' => [
                'Nous coopérons avec les services d’enquête et répondons aux réquisitions judiciaires.',
            ],
        ],
        [
            'id' => 'urgence',
            'title' => 'En cas de danger immédiat',
            'items' => [
                'un enfant est en danger immédiat : appelez le 17 (police) ou le 112 (numéro d’urgence européen) ;',
                'enfance en danger : 119, numéro gratuit, joignable 24 h/24 ;',
                'signaler un contenu illicite en ligne aux autorités françaises : internet-signalement.gouv.fr (PHAROS) ;',
                'hors de France : contactez la police locale ou l’autorité nationale compétente, par exemple la CyberTipline du NCMEC aux États-Unis (report.cybertip.org).',
            ],
        ],
        [
            'id' => 'contact',
            'title' => 'Contact désigné',
            'paragraphs' => [
                'Le contact désigné pour la sécurité des enfants et la prévention des abus sexuels sur mineurs est joignable à l’adresse cslucki@gmail.com.',
                'Il peut répondre aux questions sur nos pratiques et sur leur respect, y compris de la part de Google ou des autorités. L’éditeur, l’Association AMT, est identifié dans les mentions légales.',
            ],
            'link' => 'contact',
        ],
    ],
];
