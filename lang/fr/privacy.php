<?php

// TASK-1898 — Politique de confidentialite publique (/confidentialite), exigee
// par Google Play. CHAQUE phrase decrit un fait verifie dans le code ou dans
// le jumeau de la PROD (detail et references : TASK file). Ce qui n'est pas
// encore decide (durees, mecanisme de transfert, prestataire email nomme...)
// est ABSENT du texte, jamais invente : voir « A confirmer » du TASK file.

return [
    'title' => 'Politique de confidentialité',
    'meta_description' => 'Quelles données BouclePro traite, pourquoi, avec qui, et comment exercer vos droits.',
    'updated' => 'Version du 24 novembre 2026',
    'intro' => 'BouclePro est une plateforme d’entraide et de collaboration utilisée par plusieurs organisations (associations, réseaux, collectifs). Cette politique explique quelles données nous traitons, pourquoi, avec qui, et comment exercer vos droits. Elle s’applique au site bouclepro.com, aux espaces des organisations qui l’utilisent et à l’application Android BouclePro, qui affiche le même service.',
    'deletion_link' => 'Supprimer mon compte et mes données',
    'legal_link' => 'Mentions légales',

    'sections' => [
        [
            'title' => '1. Qui édite BouclePro',
            'paragraphs' => [
                'BouclePro est édité par l’Association AMT, à Marseille (France). Ses coordonnées complètes figurent dans les mentions légales. Pour toute question relative à vos données personnelles, écrivez à l’éditeur à ces coordonnées.',
            ],
            'link' => 'legal',
        ],
        [
            'title' => '2. Les données que nous traitons',
            'items' => [
                'Votre compte : nom, prénom, adresse email et mot de passe (conservé sous une forme chiffrée irréversible).',
                'Votre profil, selon ce que vous choisissez d’ajouter : structure, slogan, présentation, photo, ville, code postal, pays, téléphone, site web, liens. Vous décidez si votre email et votre téléphone sont visibles des autres membres.',
                'Ce que vous publiez : messages, messages vocaux, articles, publications, commentaires, demandes et propositions d’aide, fichiers déposés dans les Dossiers, évaluations.',
                'Vos appartenances et réglages : organisations et Boucles dont vous êtes membre, langue, préférences de notification.',
                'Des données techniques : adresse IP et type de navigateur, enregistrés lors de vos connexions et pour gérer votre session ; les signalements que vous nous envoyez.',
                'Si vous activez les notifications : une adresse technique propre à votre appareil, fournie par votre navigateur, qui permet de vous les envoyer.',
            ],
            'after' => [
                'Nous ne collectons ni votre localisation, ni vos contacts, ni de données de paiement.',
            ],
        ],
        [
            'title' => '3. Pourquoi nous les utilisons',
            'items' => [
                'Fournir le service : créer et sécuriser votre compte, montrer votre profil aux membres de vos organisations, acheminer vos messages et vos fichiers (exécution du service que vous utilisez).',
                'Vous prévenir, par email ou par notification, des activités qui vous concernent, selon vos réglages (exécution du service ; vous pouvez les modifier dans vos réglages de notification).',
                'Assurer la sécurité : protéger les comptes, détecter les connexions anormales, prévenir les abus (intérêt légitime).',
                'Proposer des fonctions d’intelligence artificielle, décrites ci-dessous.',
            ],
            'after' => [
                'Vos données ne sont jamais vendues ni utilisées à des fins publicitaires.',
            ],
        ],
        [
            'title' => '4. Intelligence artificielle',
            'paragraphs' => [
                'Certaines fonctions facultatives, lorsque votre organisation les a activées, s’appuient sur des modèles d’intelligence artificielle : assistant dans les discussions de Boucle, aide à la rédaction d’articles, recherche et synthèse dans les documents d’un Dossier, aide à la formulation d’une demande d’aide, assistant de profil, réponses aux questions posées depuis l’accueil, synthèse des connaissances d’une Boucle.',
                'Le texte concerné (et, pour les Dossiers, des extraits des documents) est alors transmis à nos prestataires d’IA, OpenAI et OpenRouter, pour produire la réponse. L’IA assiste les membres : elle ne prend aucune décision à leur place.',
            ],
        ],
        [
            'title' => '5. Qui peut voir vos données',
            'paragraphs' => [
                'Vos données sont visibles des membres des organisations auxquelles vous appartenez, selon les règles de chaque espace (Boucle publique ou privée, Dossier partagé…). Les données d’une organisation ne sont pas accessibles depuis une autre.',
                'Nous faisons appel aux prestataires techniques suivants, uniquement pour faire fonctionner le service :',
            ],
            'items' => [
                'Laravel Cloud : hébergement de l’application et des fichiers ;',
                'Neon : base de données ;',
                'Cloudflare : acheminement et protection du trafic ;',
                'un prestataire de relais d’emails : envoi des emails ;',
                'le service de notification de votre navigateur ou de votre appareil (par exemple Google Firebase Cloud Messaging sur Android) : acheminement des notifications, dont le contenu est chiffré et ne lui est pas lisible ;',
                'OpenAI et OpenRouter : fonctions d’intelligence artificielle ;',
                'des réseaux de diffusion de ressources (Bunny Fonts pour les polices, jsDelivr et cdnjs pour certaines bibliothèques et icônes), qui reçoivent l’adresse IP de votre appareil lorsqu’une page les charge.',
            ],
        ],
        [
            'title' => '6. Transferts hors de l’Union européenne',
            'paragraphs' => [
                'L’application et ses fichiers sont hébergés aux États-Unis, et nos prestataires d’intelligence artificielle y sont établis. Vos données sont donc transférées et traitées hors de l’Union européenne.',
            ],
        ],
        [
            'title' => '7. Combien de temps nous les conservons',
            'items' => [
                'Messages vocaux : supprimés automatiquement 5 jours après leur envoi.',
                'Votre compte et vos contenus : conservés tant que votre compte est ouvert. Après sa fermeture, voir la page « Supprimer mon compte et mes données ».',
                'Sauvegardes : la base de données peut être restaurée sur les 7 derniers jours ; une donnée effacée disparaît donc des sauvegardes de la base au plus tard 7 jours après.',
            ],
            'link' => 'deletion',
        ],
        [
            'title' => '8. Cookies',
            'paragraphs' => [
                'Nous utilisons uniquement des cookies nécessaires au fonctionnement du service : maintien de votre connexion (session, et option « Rester connecté sur cet appareil », valable 400 jours au plus), protection des formulaires, reconnaissance d’un visiteur sur la page publique d’une organisation, et mémorisation de votre refus de l’invitation à installer l’application. Aucun cookie publicitaire ni de mesure d’audience.',
            ],
        ],
        [
            'title' => '9. Vos droits',
            'paragraphs' => [
                'Vous pouvez accéder à vos données, les rectifier (la plupart depuis votre profil), demander leur effacement ou leur portabilité, demander la limitation de leur traitement et vous opposer aux traitements fondés sur l’intérêt légitime.',
                'Pour exercer ces droits, écrivez à l’éditeur aux coordonnées des mentions légales. Nous répondons dans un délai d’un mois, sous réserve des exceptions et prolongations prévues par la loi. Vous pouvez aussi adresser une réclamation à la CNIL (www.cnil.fr).',
            ],
            'link' => 'legal',
        ],
        [
            'title' => '10. Sécurité',
            'paragraphs' => [
                'Connexions chiffrées (HTTPS), mots de passe chiffrés de manière irréversible, contenu des notifications chiffré, cloisonnement des données entre organisations.',
            ],
        ],
        [
            'title' => '11. Modifications',
            'paragraphs' => [
                'Toute modification de cette politique sera publiée sur cette page, avec sa date.',
            ],
        ],
    ],
];
