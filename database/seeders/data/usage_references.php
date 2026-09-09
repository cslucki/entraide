<?php

/**
 * TASK-1485 — les textes CANONIQUES des UsageReference.
 *
 * Separes de la commande a dessein : ce sont des textes editoriaux, relus par
 * des humains, et ils changeront a un rythme qui n'est pas celui du code qui
 * les seme.
 *
 * ## La regle qui gouverne chaque phrase
 *
 * Chaque affirmation a ete verifiee contre le controleur, les vues et les cles
 * de langue de la surface concernee, le 2026-09-09. Aucune fonction promise qui
 * n'existe pas, aucune route nommee qui n'existe pas.
 *
 * C'est la seule discipline qui compte pour une couche dont le role est
 * d'ANCRER un modele : un texte qui inventerait une fonctionnalite ferait
 * exactement le contraire de ce pour quoi il existe.
 *
 * ## CANONIQUE veut dire : les DOUZE surfaces, pas seulement celles qui manquent
 *
 * Une premiere version de ce fichier ne portait que les cinq surfaces absentes
 * du banc. Le test l'a fait rougir sur une base VIERGE : douze paires y
 * manquaient. C'etait un fichier cale sur l'etat d'une machine, pas un fichier
 * canonique.
 *
 * Les textes des surfaces deja pourvues sont donc repris ici, tels qu'ils sont
 * publies — ils deviennent la source versionnee. La commande n'ecrasant jamais
 * l'existant, aucune base deja pourvue n'est touchee ; mais une base neuve
 * obtient bien les vingt-quatre.
 */

return [
    'agenda' => [
        'fr' => [
            'title' => 'L\'agenda de votre Organization',
            'lines' => [
                'Vous retrouvez ici les rencontres organisées dans les Boucles dont vous êtes membre, ainsi que celles qui sont ouvertes à toute l\'Organization.',
                '',
                'Ce que vous pouvez y faire :',
                '- filtrer les rencontres à venir ou passées ;',
                '- n\'afficher qu\'une Boucle, ou qu\'un format (présentiel, en ligne, hybride) ;',
                '- ouvrir une rencontre pour en voir le détail et qui a répondu présent.',
                '',
                'L\'agenda rassemble ce qui a été organisé dans les Boucles : il ne crée pas de rencontre. Pour en proposer une, passez par la Boucle concernée.',
            ],
        ],
        'en' => [
            'title' => 'Your Organization\'s agenda',
            'lines' => [
                'Here you find the meetings organised in the Loops you belong to, plus those open to the whole Organization.',
                '',
                'What you can do here:',
                '- filter upcoming or past meetings;',
                '- narrow down to one Loop, or one format (in person, online, hybrid);',
                '- open a meeting to see its details and who said they are coming.',
                '',
                'The agenda gathers what was organised inside Loops: it does not create meetings. To propose one, go through the Loop itself.',
            ],
        ],
    ],

    'blog' => [
        'fr' => [
            'title' => 'Le blog de votre Organization',
            'lines' => [
                'Vous trouvez ici les articles publiés par votre Organization, les plus récents d\'abord, ainsi que les plus lus.',
                '',
                'Ce que vous pouvez y faire :',
                '- parcourir les articles par catégorie ou par étiquette ;',
                '- ouvrir un article pour le lire en entier.',
            ],
        ],
        'en' => [
            'title' => 'Your Organization\'s blog',
            'lines' => [
                'Here you find the articles published by your Organization, most recent first, along with the most read ones.',
                '',
                'What you can do here:',
                '- browse articles by category or tag;',
                '- open an article to read it in full.',
            ],
        ],
    ],

    'directory' => [
        'fr' => [
            'title' => 'L\'annuaire des membres',
            'lines' => [
                'Vous pouvez parcourir ici les membres de votre Organization. Chaque personne apparaît avec ce qu\'elle rend disponible : ses micro-services actifs et ses demandes ouvertes.',
                '',
                'Ce que vous pouvez y faire :',
                '- parcourir les membres et ouvrir un profil ;',
                '- repérer qui propose quelque chose, et qui cherche de l\'aide.',
                '',
                'L\'annuaire est réservé aux membres de votre Organization : il n\'est pas visible depuis l\'extérieur.',
            ],
        ],
        'en' => [
            'title' => 'The member directory',
            'lines' => [
                'Here you can browse the members of your Organization. Each person appears with what they make available: their active micro-services and their open requests.',
                '',
                'What you can do here:',
                '- browse members and open a profile;',
                '- spot who is offering something, and who is asking for help.',
                '',
                'The directory is reserved for members of your Organization: it is not visible from outside.',
            ],
        ],
    ],

    'dossiers' => [
        'fr' => [
            'title' => 'Vos Dossiers',
            'lines' => [
                'Les Dossiers rangent vos documents. Trois espaces cohabitent : « Mes documents », votre espace personnel ; « Partagés », ce que vous avez partagé ou ce qu\'on a partagé avec vous ; et « Boucles », les Dossiers portés par les Boucles dont vous êtes membre.',
                '',
                'Ce que vous pouvez y faire :',
                '- naviguer entre ces trois espaces ;',
                '- ouvrir un Dossier pour voir ses fichiers et ses articles.',
            ],
        ],
        'en' => [
            'title' => 'Your Folders',
            'lines' => [
                'Folders hold your documents. Three spaces coexist: "My documents", your personal space; "Shared", what you shared or what was shared with you; and "Loops", the Folders carried by the Loops you belong to.',
                '',
                'What you can do here:',
                '- move between these three spaces;',
                '- open a Folder to see its files and articles.',
            ],
        ],
    ],

    'exchanges' => [
        'fr' => [
            'title' => 'Les échanges de votre Organization',
            'lines' => [
                'Cet espace rassemble deux choses : les demandes d\'aide publiées par les membres, et les propositions d\'aide que d\'autres mettent à disposition.',
                '',
                'Ce que vous pouvez y faire :',
                '- basculer entre les demandes et les propositions ;',
                '- rechercher, trier par date, par points ou par note ;',
                '- filtrer par mode — à distance ou sur site.',
                '',
                'Deux boutons vous permettent d\'y contribuer : « Demander de l\'aide » et « Proposer de l\'aide ».',
            ],
        ],
        'en' => [
            'title' => 'Your Organization\'s exchanges',
            'lines' => [
                'This space brings together two things: help requests published by members, and help offers that others make available.',
                '',
                'What you can do here:',
                '- switch between requests and offers;',
                '- search, sort by date, points or rating;',
                '- filter by delivery mode — remote or on site.',
                '',
                'Two buttons let you contribute: "Ask for help" and "Offer help".',
            ],
        ],
    ],

    'organization_home' => [
        'fr' => [
            'title' => 'Accueil de l\'organisation',
            'lines' => [
                'Cette page présente l\'organisation et les informations qu\'elle a choisi de rendre publiques sur BouclePro. Vous pouvez y découvrir ce qu\'elle fait et les espaces qu\'elle met à disposition. BouclePro IA peut vous aider à comprendre ce que vous consultez et à vous orienter vers les informations publiques utiles. Les espaces réservés aux membres nécessitent un compte et les droits correspondants.',
            ],
        ],
        'en' => [
            'title' => 'Organization home',
            'lines' => [
                'This page introduces the organization and the information it has chosen to make public on BouclePro. You can discover what it does and the spaces it provides. BouclePro AI can help you understand what you are viewing and guide you towards useful public information. Member-only spaces require an account and the appropriate access rights.',
            ],
        ],
    ],

    'shell_welcome' => [
        'fr' => [
            'title' => 'Accueil public d\'une organisation',
            'lines' => [
                "Cet espace est l'accueil PUBLIC d'une organisation sur BouclePro : il s'adresse a un visiteur qui n'a pas de compte.",
                "Ce qu'on peut y faire : comprendre ce que fait cette organisation et ce qu'elle propose, poser une question a son assistant IA public, et decider si l'on souhaite creer un compte pour la rejoindre.",
                "Comment s'en servir : ecrire sa question ou son intention en quelques mots ; l'assistant repond a partir des seules informations publiques de l'organisation et oriente vers la prochaine etape utile.",
                "Ce que cet espace ne fait pas : il n'inscrit personne, n'envoie rien, ne publie rien et n'a acces a aucun contenu prive (membres, boucles, dossiers, messages). Il ne connait pas les autres visiteurs.",
                "Pour participer (rejoindre des boucles, echanger avec les membres, partager des dossiers), il faut creer un compte depuis la page d'inscription de l'organisation.",
            ],
        ],
        'en' => [
            'title' => 'Public welcome of an organization',
            'lines' => [
                'This space is the PUBLIC welcome of an organization on BouclePro: it is meant for a visitor who has no account.',
                'What you can do here: understand what this organization does and offers, ask its public AI assistant a question, and decide whether you want to create an account to join it.',
                'How to use it: write your question or your intention in a few words; the assistant answers from the public information of the organization only, and points to the next useful step.',
                'What this space does not do: it registers nobody, sends nothing, publishes nothing and has no access to any private content (members, loops, folders, messages). It does not know other visitors.',
                'To take part (join loops, talk with members, share folders), create an account from the registration page of the organization.',
            ],
        ],
    ],

    'dashboard' => [
        'fr' => [
            'title' => 'Votre tableau de bord',
            'lines' => [
                "Cet espace est le tableau de bord PERSONNEL d'un membre : il ne montre que ce qui appartient a la personne connectee.",
                "Ce qu'on y trouve : ses propositions d'aide actives, ses demandes d'aide ouvertes, ses echanges en cours, et ses messages recents. Si l'organisation a active les profils IA, une invitation a creer le sien peut aussi y figurer.",
                "Ce qu'on peut y faire : creer une proposition d'aide, demander de l'aide, reprendre un echange en cours ou repondre a un message.",
                "Ce que cet espace ne montre pas : les autres membres, leurs echanges, ni aucune statistique de l'organisation. Pour parcourir les membres, c'est l'annuaire ; pour voir ce que les autres proposent ou demandent, ce sont les echanges.",
                "Un membre d'une autre organisation ne peut pas ouvrir ce tableau de bord : la page lui explique qu'il est reserve aux membres.",
            ],
        ],
        'en' => [
            'title' => 'Your dashboard',
            'lines' => [
                'This space is a member PERSONAL dashboard: it only shows what belongs to the signed-in person.',
                'What you find here: their active help offers, their open help requests, their exchanges in progress, and their recent messages. If the organization has enabled AI profiles, an invitation to create theirs may also appear.',
                'What you can do here: create a help offer, ask for help, resume an exchange in progress, or answer a message.',
                'What this space does not show: other members, their exchanges, or any organization-wide statistic. To browse members, use the directory; to see what others offer or request, use the exchanges.',
                'A member of another organization cannot open this dashboard: the page tells them it is reserved for members.',
            ],
        ],
    ],

    'profile' => [
        'fr' => [
            'title' => 'La fiche d\'un membre',
            'lines' => [
                "Cette page est la fiche d'UN membre de l'organisation, telle que les autres membres la voient.",
                "Ce qu'on y trouve : sa presentation, ses coordonnees declarees, sa disponibilite, ce qu'il propose et ce qu'il demande, ses echanges termines, les avis recus et, le cas echeant, ses articles.",
                "Ce qu'on peut y faire : lire sa fiche, revenir a l'annuaire, et — si ce membre a active son agent IA — lui poser une question par cet agent. Un contenu inapproprie peut etre signale.",
                "Ce que cette page ne fait pas : elle n'affiche aucune conversation privee, aucun solde, et n'engage rien a la place de ce membre. Elle est reservee aux membres de la meme organisation.",
                "Pour modifier sa propre fiche, il faut passer par l'edition de son profil, pas par cette page.",
            ],
        ],
        'en' => [
            'title' => 'A member profile',
            'lines' => [
                'This page is the profile of ONE member of the organization, as other members see it.',
                'What you find here: their introduction, the details they chose to share, their availability, what they offer and what they request, their completed exchanges, the reviews they received and, where applicable, their articles.',
                'What you can do here: read the profile, go back to the directory, and — if this member enabled their AI agent — ask that agent a question. Inappropriate content can be reported.',
                'What this page does not do: it shows no private conversation, no balance, and commits to nothing on this member behalf. It is reserved for members of the same organization.',
                'To change your own profile, use the profile editing page, not this one.',
            ],
        ],
    ],

    'workshop' => [
        'fr' => [
            'title' => 'La page publique d\'un atelier',
            'lines' => [
                'Cette page presente UN atelier propose par une organisation. Elle est publique : un visiteur sans compte peut la lire.',
                "Ce qu'on y trouve : la description de l'atelier et ses prochaines sessions, avec pour chacune sa date, sa duree, son format (en presentiel, en ligne ou hybride) et un nombre de places indicatif.",
                "Deux gestes DIFFERENTS y coexistent, et il ne faut pas les confondre. Un visiteur sans compte peut CHOISIR une session : c'est un interet, pas une inscription, et cela ne reserve aucune place. Un membre dont l'email est verifie peut CONFIRMER sa participation, et l'annuler ensuite.",
                "Pour passer de l'interet a la participation, il faut creer un compte : apres la verification de l'email, on revient sur cette page pour confirmer sa place. Une session complete refuse la confirmation et le dit.",
                "Ce que cette page ne fait pas : elle ne cree pas d'atelier, n'en modifie aucun et n'envoie rien. Les ateliers se creent depuis l'administration de l'organisation.",
            ],
        ],
        'en' => [
            'title' => 'The public page of a workshop',
            'lines' => [
                'This page presents ONE workshop offered by an organization. It is public: a visitor without an account can read it.',
                'What you find here: the workshop description and its upcoming sessions, each with its date, duration, format (in person, online or hybrid) and an indicative number of seats.',
                'Two DIFFERENT gestures live here, and they must not be confused. A visitor without an account can CHOOSE a session: that is an interest, not a registration, and it reserves no seat. A member with a verified email can CONFIRM their attendance, and cancel it afterwards.',
                'To move from interest to attendance, create an account: after the email verification, you come back to this page to confirm your seat. A full session refuses the confirmation and says so.',
                'What this page does not do: it creates no workshop, changes none, and sends nothing. Workshops are created from the organization administration.',
            ],
        ],
    ],

    'workshop_session' => [
        'fr' => [
            'title' => 'Une session d\'atelier',
            'lines' => [
                "Une session est UNE occurrence datee d'un atelier : une date, une duree, un format (en presentiel, en ligne ou hybride) et un nombre de places indicatif.",
                "Une session n'a pas de page a elle. Elle se consulte et se choisit depuis la page publique de son atelier, dans la liste des prochaines sessions.",
                "Deux gestes distincts s'y appliquent. Un visiteur sans compte peut marquer son interet pour une session : cela ne reserve aucune place. Un membre dont l'email est verifie peut confirmer sa participation, puis l'annuler.",
                "Une session complete refuse toute nouvelle confirmation et l'indique explicitement.",
                "Ce qui ne se fait pas depuis une session : la creer, la modifier ou la supprimer. Ces gestes appartiennent a l'administration de l'organisation.",
            ],
        ],
        'en' => [
            'title' => 'A workshop session',
            'lines' => [
                'A session is ONE dated occurrence of a workshop: a date, a duration, a format (in person, online or hybrid) and an indicative number of seats.',
                'A session has no page of its own. It is read and chosen from the public page of its workshop, in the list of upcoming sessions.',
                'Two distinct gestures apply to it. A visitor without an account can mark their interest in a session: this reserves no seat. A member with a verified email can confirm their attendance, then cancel it.',
                'A full session refuses any new confirmation and says so explicitly.',
                'What cannot be done from a session: creating, changing or deleting it. Those belong to the organization administration.',
            ],
        ],
    ],

    'signup' => [
        'fr' => [
            'title' => 'Creer un compte dans une organisation',
            'lines' => [
                'Cette page sert a rejoindre UNE organisation en creant un compte. Elle est publique.',
                "Ce qui est demande : un prenom, un nom, une adresse email, un telephone, un pays et un mot de passe. Le compte est cree dans l'organisation depuis laquelle la page est ouverte.",
                "Ce qui se passe ensuite : un email de verification est envoye. Tant que l'adresse n'est pas verifiee, certaines actions restent fermees — confirmer sa participation a une session d'atelier, par exemple.",
                "Si la personne etait venue par une invitation ou depuis une page d'atelier, elle est ramenee la ou elle avait commence apres la verification, plutot que sur une page generique.",
                "Ce que cette page ne fait pas : elle ne cree aucune organisation et n'inscrit a rien d'autre qu'a un compte. Une personne qui a deja un compte doit se connecter, pas s'inscrire une seconde fois.",
            ],
        ],
        'en' => [
            'title' => 'Create an account in an organization',
            'lines' => [
                'This page is where you join ONE organization by creating an account. It is public.',
                'What is asked: a first name, a last name, an email address, a phone number, a country and a password. The account is created in the organization whose page you opened.',
                'What happens next: a verification email is sent. Until the address is verified, some actions stay closed — confirming attendance to a workshop session, for instance.',
                'If the person arrived through an invitation or from a workshop page, they are brought back where they started after the verification, rather than to a generic page.',
                'What this page does not do: it creates no organization, and signs you up to nothing beyond an account. Someone who already has an account should sign in, not register a second time.',
            ],
        ],
    ],
];
