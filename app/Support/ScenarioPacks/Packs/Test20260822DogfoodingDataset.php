<?php

namespace App\Support\ScenarioPacks\Packs;

/**
 * TASK-1274 — socle de donnees FR du dataset de dogfooding `test20260822`
 * (decision produit Cyril/Master, brief du 2026-08-23) : profils humains des
 * 4 personas, referentiels de l'Organization (6 categories, skills issus des
 * CV), profils IA membre publies. Langue source canonique : FRANCAIS.
 *
 * Pure donnee, aucune logique : `Test20260822DogfoodingPack` consomme ces
 * tableaux. Tout ce qui figure ici est TRACABLE aux CV des 4 personnes ;
 * aucun skill, aucune competence, aucun fait invente.
 *
 * Coordonnees : AUCUNE coordonnee personnelle reelle. Les telephones sont des
 * valeurs explicitement DEMO (`(DEMO)` dans la valeur, `show_phone = false` :
 * jamais affichees). Les liens sont des pages professionnelles publiques.
 * `address_line1/2`, `postal_code`, `membership_value` (Organization sans
 * adhesion) et `location` (champ mort, remplace par `city` + `country_code`)
 * ne sont PAS renseignes.
 */
final class Test20260822DogfoodingDataset
{
    /** Bonus de bienvenue : valeur codee en dur par le produit (RegisteredUserController), pas `organizations.welcome_points`. */
    public const WELCOME_POINTS = 100;

    public const WELCOME_REASON = 'welcome_bonus';

    /**
     * Profils humains — champs exiges par `EnsureProfileComplete` (first_name,
     * name, phone, city, country_code, bio) + locale, visibilite, liens.
     *
     * @var array<string, array<string, mixed>>
     */
    public const HUMAN_PROFILES = [
        'test_cyril' => [
            'first_name' => 'Cyril',
            'name' => 'Slucki',
            'city' => 'Marseille',
            'country_code' => 'FR',
            'phone' => '+33 6 00 00 00 01 (DEMO)',
            'preferred_locale' => 'fr',
            'is_available' => true,
            'show_email' => false,
            'show_phone' => false,
            'linkedin_url' => 'https://www.linkedin.com/in/cslucki',
            'website' => 'https://bouclepro.com',
            'bio' => 'Fondateur de CyberWorkers (1996) et de BouclePro. Trente ans d\'expérimentation sur le travail numérique : télétravail, pédagogie digitale, inclusion par l\'emploi, communautés professionnelles. Je conçois des dispositifs où l\'IA clarifie et structure, pendant que l\'humain garde la responsabilité du sens. Directeur d\'un organisme de formation certifié Qualiopi. Auteur de « Télétravail, les clés de la réussite ».',
        ],
        'test_roger' => [
            'first_name' => 'Roger',
            'name' => 'Malina',
            'city' => 'Richardson',
            'country_code' => 'US',
            'phone' => '+1 972 000 0002 (DEMO)',
            'preferred_locale' => 'fr',
            'is_available' => true,
            'show_email' => false,
            'show_phone' => false,
            'linkedin_url' => 'https://www.linkedin.com/in/rmalina',
            'website' => 'https://www.leonardo.info',
            'bio' => 'Astrophysicien et chercheur en art-science. Professeur d\'art et technologie et professeur de physique à UT Dallas, où je dirige l\'ArtSciLab. Président de Leonardo/OLATS à Paris et éditeur exécutif des publications Leonardo chez MIT Press. Ancien directeur de recherche au CNRS, au Laboratoire d\'Astrophysique de Marseille. Cofondateur du programme de résidences art-science de l\'IMERA.',
        ],
        'test_kiran' => [
            'first_name' => 'Kiran',
            'name' => 'Sundhararaajan',
            'city' => 'Dallas',
            'country_code' => 'US',
            'phone' => '+1 972 000 0003 (DEMO)',
            'preferred_locale' => 'fr',
            'is_available' => true,
            'show_email' => false,
            'show_phone' => false,
            'linkedin_url' => 'https://www.linkedin.com/in/kiran-akshay',
            'website' => null,
            'bio' => 'Ingénieur logiciel, quatre ans d\'expérience, en master d\'informatique à UT Dallas avec une spécialisation en intelligence artificielle et apprentissage automatique. Analyste et développeur web à l\'ArtSciLab. Auparavant chez Ernst & Young, doodleblue et Smartbell. Je travaille surtout sur le back-end Python, l\'automatisation de tâches répétitives, la collecte de données web et la vision par ordinateur.',
        ],
        'test_sana' => [
            'first_name' => 'Sana',
            'name' => 'Qureshi',
            'city' => 'Dallas',
            'country_code' => 'US',
            'phone' => '+1 972 000 0004 (DEMO)',
            'preferred_locale' => 'fr',
            'is_available' => true,
            'show_email' => false,
            'show_phone' => false,
            'linkedin_url' => 'https://www.linkedin.com/in/sanaqureshii',
            'website' => null,
            'bio' => 'Spécialiste des opérations de revenus et de l\'optimisation de processus, cinq ans d\'expérience à transformer des flux financiers complexes en décisions lisibles. Analyste de recherche et responsable des opérations financières à l\'ArtSciLab, où je pilote un budget de 61 000 dollars. En master Business Analytics et intelligence artificielle à UT Dallas. Python, SQL, tableaux de bord.',
        ],
    ];

    /**
     * 6 categories. L'Organization est en `transactions_naming = b2c` :
     * c'est `name_b2c` qui s'affiche ; `name_b2b` est NOT NULL en base.
     *
     * @var array<string, array{name_b2c: string, name_b2b: string, color: string}>
     */
    public const CATEGORIES = [
        'numerique-outils' => ['name_b2c' => 'Outils numériques', 'name_b2b' => 'Transformation numérique', 'color' => '#2563eb'],
        'donnees-analyse' => ['name_b2c' => 'Données & analyse', 'name_b2b' => 'Data et pilotage', 'color' => '#0f766e'],
        'recherche-publication' => ['name_b2c' => 'Recherche & publication', 'name_b2b' => 'R&D et publication scientifique', 'color' => '#7c3aed'],
        'communication-contenu' => ['name_b2c' => 'Communication & contenu', 'name_b2b' => 'Communication éditoriale', 'color' => '#be123c'],
        'emploi-transitions' => ['name_b2c' => 'Emploi & transitions', 'name_b2b' => 'Accompagnement des parcours', 'color' => '#b45309'],
        'design-produit' => ['name_b2c' => 'Design & produit', 'name_b2b' => 'Design produit', 'color' => '#4d7c0f'],
    ];

    /**
     * Skills, par slug de categorie — UNIQUEMENT ce qui figure dans les CV.
     * Le slug du skill est derive du nom (`Str::slug`), stable.
     *
     * @var array<string, list<string>>
     */
    public const SKILLS = [
        'numerique-outils' => [
            'Télétravail', 'Automatisation', 'Python', 'Django', 'WordPress',
            'Développement back-end', 'API', 'Prototypage no-code', 'Workflows IA',
        ],
        'donnees-analyse' => [
            'SQL', 'Tableaux de bord', 'Analyse de données', 'Apprentissage automatique',
            'Collecte de données web', 'Optimisation de processus', 'Suivi budgétaire', 'Jira',
        ],
        'recherche-publication' => [
            'Astrophysique', 'Cosmologie observationnelle', 'Recherche scientifique',
            'Édition scientifique', 'Relecture d\'article', 'Art-science', 'Vision par ordinateur',
        ],
        'communication-contenu' => [
            'Rédaction éditoriale', 'Production vidéo', 'Animation de communauté', 'Relations médias', 'Podcast',
        ],
        'emploi-transitions' => [
            'Ingénierie de formation', 'Pédagogie digitale', 'Accompagnement de parcours',
            'Insertion par l\'emploi', 'Entrepreneuriat',
        ],
        'design-produit' => [
            'Stratégie produit', 'Conception d\'expérience d\'apprentissage', 'Design de dispositifs d\'entraide',
        ],
    ];

    /**
     * Profils IA membre (`member_ai_profiles`), en francais, publies.
     *
     * `tone` porte un code du referentiel produit
     * (`config('member_ai_profile.tones')`, valide par le wizard) ; la
     * formulation complete du ton demandee par le brief vit dans
     * `structured_profile.tone`, que `MemberProfileAgentResponder` injecte
     * telle quelle dans le prompt. Dans `structured_profile`,
     * `target_audience` et `problems_helped` sont des CHAINES : le responder
     * les concatene directement (`buildProfileDataLines()`), un tableau y
     * leverait « Array to string conversion ».
     *
     * @var array<string, array<string, mixed>>
     */
    public const AI_PROFILES = [
        'test_cyril' => [
            'member_profile_summary' => 'Cyril conçoit des dispositifs de travail et d\'entraide où l\'IA clarifie et structure pendant que l\'humain garde la responsabilité du sens : télétravail, formation, communautés professionnelles, inclusion par l\'emploi.',
            'service_scope' => 'Structurer le télétravail d\'une équipe, clarifier une intention de projet, préparer une reconversion, monter un parcours de formation, animer une communauté d\'entraide.',
            'experience_context' => 'Fondateur de CyberWorkers (1996) et de BouclePro ; trente ans d\'expérimentation sur le travail numérique ; directeur d\'un organisme de formation certifié Qualiopi ; auteur de « Télétravail, les clés de la réussite ».',
            'tone' => 'direct',
            'tone_label' => 'Direct, concret, orienté action.',
            'preferred_contact_action' => 'envoyer_demande_echange',
            'generated_summary' => 'Cyril aide les indépendants, les personnes en transition, les responsables de formation et les porteurs de projet à structurer le télétravail, clarifier une intention, préparer une reconversion, monter un parcours de formation ou animer une communauté d\'entraide. Il ne fait ni développement logiciel, ni machine learning, ni analyse financière.',
            'skills' => ['Télétravail', 'Ingénierie de formation', 'Pédagogie digitale', 'Animation de communauté', 'Accompagnement de parcours', 'Insertion par l\'emploi', 'Entrepreneuriat', 'Stratégie produit', 'Design de dispositifs d\'entraide', 'Workflows IA'],
            'problems_helped' => [
                'Structurer le télétravail d\'une équipe',
                'Clarifier une intention de projet',
                'Préparer une reconversion',
                'Monter un parcours de formation',
                'Animer une communauté d\'entraide',
            ],
            'help_types' => ['Avis rapide sur une intention', 'Méthode et cadre de travail', 'Relecture d\'un dispositif de formation', 'Mise en relation dans une communauté', 'Atelier court'],
            'target_audience' => ['Indépendants', 'Personnes en transition', 'Responsables de formation', 'Porteurs de projet'],
            'boundaries' => ['Pas de développement logiciel', 'Pas de machine learning', 'Pas d\'analyse financière'],
            'good_request_examples' => [
                'Mon équipe de six personnes passe en télétravail partiel : comment structurer les rituels et les outils ?',
                'Je veux monter un parcours de formation de trois jours sur le travail à distance, par où commencer ?',
                'Je suis en reconversion après quinze ans dans le même métier : comment clarifier mon projet ?',
            ],
            'bad_request_examples' => [
                'Peux-tu développer un script Python pour automatiser mes exports ?',
                'Peux-tu analyser la rentabilité de mon budget prévisionnel ?',
            ],
        ],
        'test_roger' => [
            'member_profile_summary' => 'Roger est astrophysicien et chercheur en art-science : il dirige l\'ArtSciLab à UT Dallas, préside Leonardo/OLATS et dirige les publications Leonardo chez MIT Press.',
            'service_scope' => 'Relire un article scientifique avant soumission, cadrer une collaboration entre artistes et scientifiques, ouvrir des portes dans les réseaux art-science, structurer un programme de résidence, situer un travail dans la littérature.',
            'experience_context' => 'Professeur d\'art et technologie et professeur de physique à UT Dallas (direction de l\'ArtSciLab) ; président de Leonardo/OLATS ; éditeur exécutif des publications Leonardo (MIT Press) ; ancien directeur de recherche au CNRS (Laboratoire d\'Astrophysique de Marseille) ; cofondateur du programme de résidences art-science de l\'IMERA.',
            'tone' => 'sobre',
            'tone_label' => 'Académique, exigeant, bienveillant.',
            'preferred_contact_action' => 'envoyer_demande_echange',
            'generated_summary' => 'Roger aide les doctorants, les artistes-chercheurs et les responsables de laboratoire à relire un article avant soumission, cadrer une collaboration art-science, ouvrir des portes dans les réseaux Leonardo, structurer un programme de résidence ou situer un travail dans la littérature. Il ne code pas, n\'administre pas de site et ne donne pas de conseil financier.',
            'skills' => ['Astrophysique', 'Cosmologie observationnelle', 'Recherche scientifique', 'Édition scientifique', 'Relecture d\'article', 'Art-science', 'Relations médias', 'Rédaction éditoriale'],
            'problems_helped' => [
                'Relire un article scientifique avant soumission',
                'Cadrer une collaboration entre artistes et scientifiques',
                'Ouvrir des portes dans les réseaux art-science',
                'Structurer un programme de résidence',
                'Situer un travail dans la littérature',
            ],
            'help_types' => ['Relecture d\'article', 'Avis sur un cadrage de collaboration', 'Mise en relation dans les réseaux art-science', 'Conseil sur un programme de résidence'],
            'target_audience' => ['Doctorants', 'Artistes-chercheurs', 'Responsables de laboratoire'],
            'boundaries' => ['Ne code pas', 'Pas d\'administration de site', 'Pas de conseil financier'],
            'good_request_examples' => [
                'Pouvez-vous relire la structure de mon article avant soumission et nommer ses deux principales faiblesses ?',
                'Nous montons une résidence art-science de trois mois : comment la structurer ?',
                'Où situer mon travail sur la visualisation de données astrophysiques dans la littérature art-science ?',
            ],
            'bad_request_examples' => [
                'Pouvez-vous corriger le plugin WordPress de notre laboratoire ?',
                'Pouvez-vous bâtir le budget de notre projet ?',
            ],
        ],
        'test_kiran' => [
            'member_profile_summary' => 'Kiran est ingénieur logiciel (quatre ans d\'expérience), analyste et développeur web à l\'ArtSciLab, en master d\'informatique à UT Dallas spécialisé en intelligence artificielle et apprentissage automatique.',
            'service_scope' => 'Automatiser une tâche répétitive en Python, dépanner ou fiabiliser un site WordPress, mettre en place une collecte de données web, concevoir une API back-end, expliquer un modèle de vision par ordinateur.',
            'experience_context' => 'Back-end Python et Django, automatisation, collecte de données web et vision par ordinateur ; auparavant chez Ernst & Young, doodleblue et Smartbell ; analyste et développeur web à l\'ArtSciLab.',
            'tone' => 'pedagogique',
            'tone_label' => 'Pédagogue, pragmatique.',
            'preferred_contact_action' => 'envoyer_demande_echange',
            'generated_summary' => 'Kiran aide les chercheurs non-développeurs, les petites structures et les étudiants à automatiser une tâche répétitive en Python, dépanner un site WordPress, mettre en place une collecte de données web, concevoir une API back-end ou comprendre un modèle de vision par ordinateur. Il ne fait ni médiation art-science, ni conseil juridique, ni gestion budgétaire.',
            'skills' => ['Python', 'Django', 'WordPress', 'Automatisation', 'Développement back-end', 'API', 'Collecte de données web', 'Vision par ordinateur', 'Apprentissage automatique', 'Jira'],
            'problems_helped' => [
                'Automatiser une tâche répétitive en Python',
                'Dépanner ou fiabiliser un site WordPress',
                'Mettre en place une collecte de données web',
                'Concevoir une API back-end',
                'Expliquer un modèle de vision par ordinateur',
            ],
            'help_types' => ['Dépannage technique', 'Script ou prototype Python', 'Explication d\'un outil ou d\'un modèle', 'Revue d\'une architecture back-end'],
            'target_audience' => ['Chercheurs non-développeurs', 'Petites structures', 'Étudiants'],
            'boundaries' => ['Pas de médiation art-science', 'Pas de conseil juridique', 'Pas de gestion budgétaire'],
            'good_request_examples' => [
                'Je renomme et classe 300 fichiers à la main chaque semaine : peut-on automatiser ça en Python ?',
                'Notre site WordPress affiche une page blanche depuis une mise à jour, par où chercher ?',
                'Comment collecter automatiquement les publications d\'un site pour alimenter une base de données ?',
            ],
            'bad_request_examples' => [
                'Peux-tu arbitrer le budget de notre projet de recherche ?',
                'Peux-tu rédiger le contrat entre l\'artiste et le laboratoire ?',
            ],
        ],
        'test_sana' => [
            'member_profile_summary' => 'Sana est spécialiste des opérations de revenus et de l\'optimisation de processus : elle transforme des flux financiers complexes en décisions lisibles, et pilote les opérations financières de l\'ArtSciLab.',
            'service_scope' => 'Construire un tableau de bord lisible en SQL, nettoyer un processus administratif ou financier, organiser et suivre un budget de projet, poser des indicateurs, préparer une décision à partir de données.',
            'experience_context' => 'Cinq ans d\'expérience en opérations de revenus et optimisation de processus ; analyste de recherche et responsable des opérations financières à l\'ArtSciLab (budget de 61 000 dollars) ; master Business Analytics et intelligence artificielle à UT Dallas ; Python, SQL, tableaux de bord.',
            'tone' => 'sobre',
            'tone_label' => 'Clair, structuré, orienté décision.',
            'preferred_contact_action' => 'envoyer_demande_echange',
            'generated_summary' => 'Sana aide les responsables de laboratoire, les porteurs de projet et les équipes sans fonction data à construire un tableau de bord lisible en SQL, nettoyer un processus administratif ou financier, organiser et suivre un budget, poser des indicateurs ou préparer une décision à partir de données. Elle ne fait ni astrophysique, ni développement d\'application, ni conseil juridique.',
            'skills' => ['SQL', 'Tableaux de bord', 'Analyse de données', 'Optimisation de processus', 'Suivi budgétaire', 'Python'],
            'problems_helped' => [
                'Construire un tableau de bord lisible en SQL',
                'Nettoyer un processus administratif ou financier',
                'Organiser et suivre un budget de projet',
                'Poser des indicateurs',
                'Préparer une décision à partir de données',
            ],
            'help_types' => ['Tableau de bord ou requête SQL', 'Revue d\'un processus', 'Cadrage d\'un suivi budgétaire', 'Préparation d\'une décision chiffrée'],
            'target_audience' => ['Responsables de laboratoire', 'Porteurs de projet', 'Équipes sans fonction data'],
            'boundaries' => ['Pas d\'astrophysique', 'Pas de développement d\'application', 'Pas de conseil juridique'],
            'good_request_examples' => [
                'Nous suivons notre budget de projet dans trois fichiers différents : comment en faire un tableau de bord lisible ?',
                'Quels indicateurs poser pour suivre les dépenses d\'un laboratoire sur l\'année ?',
                'Comment préparer une décision d\'achat d\'équipement à partir de nos données de dépenses ?',
            ],
            'bad_request_examples' => [
                'Peux-tu développer l\'application mobile de notre laboratoire ?',
                'Peux-tu relire notre article de cosmologie ?',
            ],
        ],
    ];
}
