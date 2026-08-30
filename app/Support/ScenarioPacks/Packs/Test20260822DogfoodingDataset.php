<?php

namespace App\Support\ScenarioPacks\Packs;

/**
 * TASK-1274 — socle de donnees FR du dataset de dogfooding `test20260822`
 * (decision produit Cyril/Master, brief du 2026-08-23) : profils humains des
 * 4 personas, referentiels de l'Organization (6 categories, skills issus des
 * CV), profils IA membre publies. Langue source canonique : FRANCAIS.
 *
 * Pure donnee, aucune logique : `Test20260822DogfoodingPack` consomme ces
 * tableaux. `test_cyril` reste tracable au CV de son auteur (donnee propre,
 * consentie, publiee sur son propre site). TASK-1344 (2026-08-30, GO Cyril +
 * MASTER, VALIDATION_LEVEL SENSITIVE) : `test_roger`, `test_kiran` et
 * `test_sana` sont des PERSONAS FICTIFS — noms, LinkedIn et faits de
 * carriere externes reels neutralises (identity closure : aucune occurrence
 * ne doit permettre de re-identifier les trois personnes reelles tierces
 * initialement utilisees). Skills/competences/gabarit de service inchanges
 * (aucun fait invente sur CE plan, seule l'identite change).
 *
 * Coordonnees : AUCUNE coordonnee personnelle reelle. Les telephones sont des
 * valeurs explicitement DEMO (`(DEMO)` dans la valeur, `show_phone = false` :
 * jamais affichees). `linkedin_url`/`website`, quand renseignes, sont des
 * pages professionnelles publiques (uniquement pour `test_cyril` depuis
 * TASK-1344) ou `null`.
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
     * TASK-1275 — ce que chaque Boucle EST : son type, ses membres et leurs
     * roles, et les Cards qu'elle garde en plus de son preset. MAPPING VALIDE
     * par Cyril (brief T1275, 2026-08-23), a appliquer tel quel. Les 7 types
     * sont couverts : `writing` x2, `project` x3, `coaching`, `general`,
     * `training`, `peer_support`, `networking`.
     *
     * Cle : le nom EXACT du repertoire de corpus (`Test20260822DogfoodingPack::
     * LOOP_DIRECTORIES`) — toute Boucle declaree ici doit y figurer, et
     * reciproquement (verifie par le pack, bruyant sinon).
     *
     * `members` : persona -> role CANONIQUE (`LoopRoleRegistry::CANONICAL` :
     * `owner`, `facilitator`, `member`). JAMAIS `moderator` : alias legacy en
     * lecture seule (`LoopRoleRegistry::LEGACY_ALIASES`), jamais ecrit. Le
     * pack applique les `owner` AVANT les autres roles — invariant
     * `last_owner` (`LoopGovernanceService::changeRole`) : sur 09-UT Dallas,
     * Antoine (persona `test_roger`, TASK-1344) est nomme proprietaire avant
     * que Cyril ne soit retrograde.
     *
     * `kept_cards` : Cards ACTIVES en plus du preset du type. Les presets sont
     * additifs (`LoopTypeRegistry::applyPreset()` n'enleve rien) : retyper une
     * Boucle `general` lui laisse `polls`, `events`, `dossiers`… Le pack
     * eteint explicitement tout ce qui est hors preset ET hors `kept_cards`
     * (`LoopCardCompositionService::disable()`, rien n'est supprime).
     * `core.dossiers` est garde PARTOUT (decision T1275) : 4 presets ne l'ont
     * pas (`coaching`, `training`, `peer_support`, `networking`) et l'eteindre
     * fermerait l'acces au Dossier (`loop_permissions.requires_card`) — 28
     * fichiers du corpus RAG, raison d'etre de `test20260822`, deviendraient
     * inaccessibles.
     *
     * `core.manifesto` n'est PLUS garde en `kept_cards` depuis TASK-1332
     * (decision Cyril) : aucune necessite fonctionnelle propre au Manifeste
     * n'a ete etablie pour aucune des 10 Boucles (seul `core.dossiers`, ci-
     * dessus, en a une, documentee) — "herite de l'ancien preset" n'est pas
     * une justification. Le pack recalcule `$wanted` depuis le preset
     * **courant** a chaque chargement : ces 9 Boucles perdent donc
     * desormais silencieusement leur Manifeste au prochain rejeu, exactement
     * l'effet produit voulu — une Card devenue optionnelle doit reellement
     * pouvoir disparaitre du default. Elle reste au catalogue, activable a
     * la demande depuis Outils. L'entree `coaching` (05) est inchangee :
     * son preset ne l'a jamais inclus, avant comme apres TASK-1332.
     *
     * `primary_cards` : les 3 outils MIS EN AVANT (barre), dans l'ordre —
     * DECLARES pour toute Boucle qui a plus de 3 Cards de grille actives
     * (07, 08, 09 : le preset + `dossiers` garde), pour que la Card qui
     * DEFINIT le type reste dans le trio (`assignments` en formation,
     * `journal` en pair-aidance, `marketplace` en reseautage — decision
     * Cyril/T1275, option « garder dossiers ET promouvoir »). Le pack
     * VERIFIE le trio a chaque chargement et n'ecrit un rang (`promote()`/
     * `demote()`, `primary_rank`) que la ou le mode derive du produit — les
     * 3 premieres actives dans l'ordre du CATALOGUE — ne le donne pas deja :
     * seule 08 (`dossiers` 38 passe avant `journal` 41) ; 07 (32/34/36 < 38)
     * et 09 (30/33/37 < 38) restent en mode derive, verifies. Un
     * reordonnancement du catalogue qui repousserait une Card du type en
     * secondaire fait echouer le chargement en le nommant, jamais en
     * silence. NULL = 3 Cards de grille au plus : le trio est complet.
     * Depuis TASK-1124, rien n'est masque au-dela de 3 : toutes les Cards
     * actives sont rendues (TASK-1128 : 5 dans la barre, puis « Autres
     * outils »).
     *
     * @var array<string, array{type: string, members: array<string, string>, kept_cards: list<string>, primary_cards: list<string>|null}>
     */
    public const LOOP_SETUP = [
        '01-COMMUNICATION' => [
            'type' => 'writing',
            'members' => ['test_cyril' => 'owner', 'test_kiran' => 'member'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => null,
        ],
        '02-DESIGN' => [
            'type' => 'project',
            'members' => ['test_cyril' => 'owner', 'test_kiran' => 'facilitator'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => null,
        ],
        '03-Post LinkedIN' => [
            'type' => 'writing',
            'members' => ['test_cyril' => 'owner', 'test_sana' => 'member'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => null,
        ],
        '04-Screens' => [
            'type' => 'project',
            'members' => ['test_cyril' => 'owner', 'test_kiran' => 'member'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => null,
        ],
        '05-Pour-la-beta1' => [
            'type' => 'coaching',
            'members' => ['test_cyril' => 'owner', 'test_sana' => 'member'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => null,
        ],
        '06-Pour_Boucles' => [
            'type' => 'general',
            'members' => ['test_cyril' => 'owner', 'test_roger' => 'member', 'test_kiran' => 'member', 'test_sana' => 'member'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => null,
        ],
        '07-Plan-262 Définition boucles et IA' => [
            'type' => 'training',
            'members' => ['test_cyril' => 'owner', 'test_kiran' => 'member', 'test_sana' => 'member'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => ['training.course_material', 'training.progression', 'training.assignments'],
        ],
        "08-Protocole d'emergence" => [
            'type' => 'peer_support',
            'members' => ['test_cyril' => 'owner', 'test_roger' => 'facilitator', 'test_sana' => 'member'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => ['core.roadmap', 'core.polls', 'core.journal'],
        ],
        '09-UT Dallas' => [
            'type' => 'networking',
            // TASK-1335 : `core.decisions` ajoutee a `kept_cards` (hors preset
            // `networking`) pour porter la Decision "Soumettre l'article a
            // Leonardo" — cf. `HISTORICAL_ACTIVITY`. Reste hors `primary_cards`
            // (le trio declare est deja au plafond) : Card secondaire, sous
            // « Autres outils ».
            'members' => ['test_roger' => 'owner', 'test_cyril' => 'facilitator', 'test_kiran' => 'member', 'test_sana' => 'member'],
            'kept_cards' => ['core.dossiers', 'core.decisions'],
            'primary_cards' => ['core.roadmap', 'core.marketplace', 'core.events'],
        ],
        '10-Aria projet européen' => [
            'type' => 'project',
            'members' => ['test_cyril' => 'owner', 'test_roger' => 'member', 'test_sana' => 'member'],
            'kept_cards' => ['core.dossiers'],
            'primary_cards' => null,
        ],
    ];

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
            'first_name' => 'Antoine',
            'name' => 'Dubreuil',
            'city' => 'Richardson',
            'country_code' => 'US',
            'phone' => '+1 972 000 0002 (DEMO)',
            'preferred_locale' => 'fr',
            'is_available' => true,
            'show_email' => false,
            'show_phone' => false,
            'linkedin_url' => null,
            'website' => null,
            'bio' => 'Astrophysicien et chercheur en art-science. Je dirige l\'ArtSciLab et anime les collaborations entre artistes et scientifiques. Ancien chercheur dans un laboratoire d\'astrophysique européen, engagé depuis dix ans dans des programmes de résidences art-science et dans l\'édition de revues interdisciplinaires.',
        ],
        'test_kiran' => [
            'first_name' => 'Maya',
            'name' => 'Marchetti',
            'city' => 'Dallas',
            'country_code' => 'US',
            'phone' => '+1 972 000 0003 (DEMO)',
            'preferred_locale' => 'fr',
            'is_available' => true,
            'show_email' => false,
            'show_phone' => false,
            'linkedin_url' => null,
            'website' => null,
            'bio' => 'Ingénieure logicielle, quatre ans d\'expérience, en master d\'informatique avec une spécialisation en intelligence artificielle et apprentissage automatique. Analyste et développeuse web à l\'ArtSciLab. Auparavant dans deux cabinets de conseil technique et une start-up. Je travaille surtout sur le back-end Python, l\'automatisation de tâches répétitives, la collecte de données web et la vision par ordinateur.',
        ],
        'test_sana' => [
            'first_name' => 'Camille',
            'name' => 'Berthet',
            'city' => 'Dallas',
            'country_code' => 'US',
            'phone' => '+1 972 000 0004 (DEMO)',
            'preferred_locale' => 'fr',
            'is_available' => true,
            'show_email' => false,
            'show_phone' => false,
            'linkedin_url' => null,
            'website' => null,
            'bio' => 'Spécialiste des opérations de revenus et de l\'optimisation de processus, cinq ans d\'expérience à transformer des flux financiers complexes en décisions lisibles. Analyste de recherche et responsable des opérations financières à l\'ArtSciLab, où je pilote le budget de fonctionnement d\'une petite équipe. En master Business Analytics et intelligence artificielle. Python, SQL, tableaux de bord.',
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
            'member_profile_summary' => 'Antoine est astrophysicien et chercheur en art-science : il dirige l\'ArtSciLab et anime les collaborations entre artistes et scientifiques.',
            'service_scope' => 'Relire un article scientifique avant soumission, cadrer une collaboration entre artistes et scientifiques, ouvrir des portes dans les réseaux art-science, structurer un programme de résidence, situer un travail dans la littérature.',
            'experience_context' => 'Direction de l\'ArtSciLab ; anime des collaborations entre artistes et scientifiques ; ancien chercheur dans un laboratoire d\'astrophysique européen ; dix ans d\'engagement dans des programmes de résidences art-science et l\'édition de revues interdisciplinaires.',
            'tone' => 'sobre',
            'tone_label' => 'Académique, exigeant, bienveillant.',
            'preferred_contact_action' => 'envoyer_demande_echange',
            'generated_summary' => 'Antoine aide les doctorants, les artistes-chercheurs et les responsables de laboratoire à relire un article avant soumission, cadrer une collaboration art-science, ouvrir des portes dans les réseaux art-science, structurer un programme de résidence ou situer un travail dans la littérature. Il ne code pas, n\'administre pas de site et ne donne pas de conseil financier.',
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
            'member_profile_summary' => 'Maya est ingénieure logicielle (quatre ans d\'expérience), analyste et développeuse web à l\'ArtSciLab, en master d\'informatique spécialisé en intelligence artificielle et apprentissage automatique.',
            'service_scope' => 'Automatiser une tâche répétitive en Python, dépanner ou fiabiliser un site WordPress, mettre en place une collecte de données web, concevoir une API back-end, expliquer un modèle de vision par ordinateur.',
            'experience_context' => 'Back-end Python et Django, automatisation, collecte de données web et vision par ordinateur ; auparavant dans deux cabinets de conseil technique et une start-up ; analyste et développeuse web à l\'ArtSciLab.',
            'tone' => 'pedagogique',
            'tone_label' => 'Pédagogue, pragmatique.',
            'preferred_contact_action' => 'envoyer_demande_echange',
            'generated_summary' => 'Maya aide les chercheurs non-développeurs, les petites structures et les étudiants à automatiser une tâche répétitive en Python, dépanner un site WordPress, mettre en place une collecte de données web, concevoir une API back-end ou comprendre un modèle de vision par ordinateur. Elle ne fait ni médiation art-science, ni conseil juridique, ni gestion budgétaire.',
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
            'member_profile_summary' => 'Camille est spécialiste des opérations de revenus et de l\'optimisation de processus : elle transforme des flux financiers complexes en décisions lisibles, et pilote les opérations financières de l\'ArtSciLab.',
            'service_scope' => 'Construire un tableau de bord lisible en SQL, nettoyer un processus administratif ou financier, organiser et suivre un budget de projet, poser des indicateurs, préparer une décision à partir de données.',
            'experience_context' => 'Cinq ans d\'expérience en opérations de revenus et optimisation de processus ; analyste de recherche et responsable des opérations financières à l\'ArtSciLab (budget de fonctionnement d\'une petite équipe) ; master Business Analytics et intelligence artificielle ; Python, SQL, tableaux de bord.',
            'tone' => 'sobre',
            'tone_label' => 'Clair, structuré, orienté décision.',
            'preferred_contact_action' => 'envoyer_demande_echange',
            'generated_summary' => 'Camille aide les responsables de laboratoire, les porteurs de projet et les équipes sans fonction data à construire un tableau de bord lisible en SQL, nettoyer un processus administratif ou financier, organiser et suivre un budget, poser des indicateurs ou préparer une décision à partir de données. Elle ne fait ni astrophysique, ni développement d\'application, ni conseil juridique.',
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

    /**
     * TASK-1335 (version 1.3.0) — activite humaine collective, distillee de la
     * matiere reelle du corpus (fichiers `08-Protocole d'emergence`,
     * `09-UT Dallas`, `10-Aria projet europeen`) et des CV des personas
     * (`HUMAN_PROFILES`, `AI_PROFILES`) tels qu'ils existaient a la creation
     * de cette TASK. TASK-1344 (2026-08-30) : les mentions nominatives et les
     * affiliations externes reelles de `test_roger`/`test_kiran` ont ete
     * neutralisees en coherence avec `HUMAN_PROFILES`/`AI_PROFILES`
     * (identity closure) ; ArtSciLab, ARIA, Horizon Europe, devCT et Silver
     * Ingenuity sont conserves tels quels (KEEP, decision MASTER).
     *
     * Decision Master : AUCUN message IA, AUCUNE `ai_interaction`, AUCUN
     * remapping de metadata pour simuler l'IA. Chaque element est ecrit par
     * la primitive canonique du produit — jamais un INSERT brut :
     * `LoopMessageService::sendUserMessage()`, `LoopPollService::create()`/
     * `vote()`, `LoopDecisionService::record()`, `LoopEventService::create()`/
     * `respond()`, et `LoopRoadmapItem::create()` (aucun service dedie
     * n'existe pour la Roadmap : `LoopRoadmapCard::createAction()` ecrit
     * directement le modele, le pack fait de meme).
     *
     * Cle : le nom EXACT du repertoire de corpus, comme `LOOP_SETUP`. Seules
     * 08/09/10 sont couvertes (perimetre du brief T1335) : chaque Boucle
     * ci-dessous DOIT figurer dans `LOOP_SETUP` (verifie par le pack),
     * jamais l'inverse — les 7 autres Boucles du pack restent sans activite
     * simulee.
     *
     * @var array<string, array{
     *     messages: list<array{key: string, persona: string, body: string}>,
     *     poll?: array{key: string, author: string, question: string, description: ?string, selection_type: string, labels: list<string>, votes: array<string, string>},
     *     decision?: array{key: string, author: string, title: string, rationale: string},
     *     event?: array{key: string, creator: string, title: string, description: string, format: string, starts_at: string, ends_at: ?string, timezone: string, location: ?string, meeting_url: ?string, responses: array<string, string>},
     *     roadmap_item?: array{key: string, creator: string, title: string, status: string, assignees: list<string>},
     * }>
     */
    public const HISTORICAL_ACTIVITY = [
        "08-Protocole d'emergence" => [
            'messages' => [
                [
                    'key' => '08-msg-1',
                    'persona' => 'test_cyril',
                    'body' => 'J\'ai remis à plat le Protocole en v0.2 : trois niveaux de mémoire — individuelle, communauté, historique — et le cycle question → clarification IA → discussion → expérimentation → documentation → publication. Le brouillon FR est dans le Dossier de la Boucle.',
                ],
                [
                    'key' => '08-msg-2',
                    'persona' => 'test_roger',
                    'body' => 'Lu. Le cycle en six étapes est clair. Pour le partager avec UT Dallas et l\'ArtSciLab il va nous falloir une version anglaise solide, pas juste une traduction rapide.',
                ],
                [
                    'key' => '08-msg-3',
                    'persona' => 'test_sana',
                    'body' => 'D\'accord avec Antoine. Le protocole dit que « les erreurs deviennent des ressources » — ce serait cohérent qu\'on vote laquelle des deux versions on pousse en premier plutôt que de trancher seuls.',
                ],
            ],
            'poll' => [
                'key' => '08-poll-fr-en-v02',
                'author' => 'test_cyril',
                'question' => 'Quelle version du Protocole d\'émergence partager en priorité ?',
                'description' => 'FR v0.2 (brouillon de travail) ou EN v0.2 (traduction pour UT Dallas et l\'ArtSciLab).',
                'selection_type' => 'single',
                'labels' => ['FR v0.2', 'EN v0.2'],
                // Antoine (persona test_roger, TASK-1344) non votant (brief
                // T1335) : c'est lui qui portera la traduction, pas lui qui
                // tranche la priorité.
                'votes' => ['test_sana' => 'EN v0.2'],
            ],
            'roadmap_item' => [
                'key' => '08-roadmap-traduction-en',
                'creator' => 'test_cyril',
                'title' => 'Finaliser la traduction anglaise du Protocole (v0.2)',
                'status' => 'todo',
                'assignees' => ['test_roger'],
            ],
        ],
        '09-UT Dallas' => [
            'messages' => [
                [
                    'key' => '09-msg-1',
                    'persona' => 'test_cyril',
                    'body' => 'Avec Antoine on a terminé une première version de l\'article « BouclePro comme environnement d\'apprentissage pour l\'AI Shepherding » — l\'idée du Shepherd qui clarifie, vérifie et garde la trace. Je le mets dans le Dossier.',
                ],
                [
                    'key' => '09-msg-2',
                    'persona' => 'test_roger',
                    'body' => 'Relu. Le parallèle berger/troupeau tient la route sur toute la longueur. On devrait le proposer à une revue interdisciplinaire art-science, ça correspond exactement à notre ligne éditoriale.',
                ],
                [
                    'key' => '09-msg-3',
                    'persona' => 'test_kiran',
                    'body' => 'Pendant qu\'on y est : l\'ArtSciLab prépare une session sur Silver Ingenuity. Je regarde les dates avec eux et je pose un Événement dès que j\'ai un créneau confirmé.',
                ],
            ],
            'decision' => [
                'key' => '09-decision-leonardo',
                'author' => 'test_cyril',
                'title' => 'Soumettre l\'article co-écrit avec Antoine à une revue interdisciplinaire art-science',
                'rationale' => 'L\'article « BouclePro comme environnement d\'apprentissage pour l\'AI Shepherding » correspond à la ligne éditoriale visée. Aucune action n\'est lancée automatiquement : la soumission reste un geste humain distinct.',
            ],
            'event' => [
                'key' => '09-event-silver-ingenuity',
                'creator' => 'test_cyril',
                'title' => 'ArtSciLab — Silver Ingenuity',
                'description' => 'Session ArtSciLab sur le projet Silver Ingenuity, à confirmer avec l\'équipe UT Dallas.',
                'format' => 'online',
                'starts_at' => '2026-10-15 14:00',
                'ends_at' => '2026-10-15 15:30',
                'timezone' => 'America/Chicago',
                'location' => null,
                'meeting_url' => 'https://artscilab.utdallas.edu/silver-ingenuity',
                // Antoine (persona test_roger, TASK-1344) sans reponse (brief
                // T1335) : aucun appel `respond()` pour lui. Maya (persona
                // test_kiran) = going.
                'responses' => ['test_kiran' => 'going'],
            ],
        ],
        '10-Aria projet européen' => [
            'messages' => [
                [
                    'key' => '10-msg-1',
                    'persona' => 'test_cyril',
                    'body' => 'Proposition ARIA v6 déposée : « Contribution au consortium ARIA — an open-source Smart Hamlet for inter-intelligence ». BouclePro y est positionné comme l\'environnement communautaire, la mémoire et les instruments d\'observation.',
                ],
                [
                    'key' => '10-msg-2',
                    'persona' => 'test_roger',
                    'body' => 'Le concept de Smart Hamlet colle bien à ce qu\'on fait déjà avec les Boucles. Horizon Europe classe ça sous « Artistic Intelligence » — un angle qu\'on n\'avait pas encore exploré.',
                ],
                [
                    'key' => '10-msg-3',
                    'persona' => 'test_sana',
                    'body' => 'Sur le positionnement : le document nous dit complémentaires à l\'infrastructure numérique cible développée par devCT. Il faudra qu\'on soit très clairs sur cette frontière dans la version finale.',
                ],
            ],
        ],
    ];
}
