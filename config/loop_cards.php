<?php

return [

    /*
     * Le nombre de Cards que la zone principale accepte.
     *
     * Trois, parce que c'est ce qui permet de comprendre en ouvrant une Boucle
     * ce qu'on y fait. Au-dela, la barre cesse d'annoncer une intention et
     * devient une boite a outils.
     *
     * Le cadre permanent — ChatLoop, Manifeste, Membres — ne compte pas : il est
     * partout, donc il ne distingue rien.
     */
    'grid_slots' => 3,

    /*
     * Le catalogue des Cards — la seule declaration.
     *
     * Une Card etait auparavant declaree a trois endroits que rien ne tenait
     * d'accord : la cle `rendered` de ce fichier, la constante
     * LoopController::RENDERED_CARDS, et une chaine de conditions sur la cle
     * dans loops/show.blade.php. Les trois disaient la meme chose ; rien ne les
     * y obligeait. Une cinquieme Card ajoutee a deux endroits sur trois passait
     * la revue et ouvrait sur un panneau vide en recette.
     *
     * Tout se lit desormais ici, a travers LoopCardRegistry.
     *
     * Champs :
     *
     *   component        composant Livewire qui rend la Card, ou null
     *   view             vue Blade qui la rend, quand elle n'a pas de composant
     *   view_permission  permission qui protege son *contenu*, distincte du fait
     *                    de posseder la Card ; null = tout membre actif
     *   required         une Card requise ne se desactive jamais
     *   order            ordre d'affichage dans le workspace
     *
     *   placement        ou la Card vit a l'ecran (TASK-1090) :
     *                      `grid`         la zone des outils, trois au maximum
     *                      `frame`        le cadre permanent, hors grille
     *                      `chat_action`  une action IA de ChatLoop
     *   category         a quoi elle sert, pour regrouper le catalogue
     *   scope            `universal` (toute Boucle) ou `contextual` (un metier)
     *   requires         Cards dont celle-ci a besoin pour avoir un sens
     *   incompatible_with Cards avec lesquelles elle ne peut pas coexister
     *   replaceable      peut-elle etre echangee dans un emplacement distinctif
     *
     * `placement` ne retire rien : une Card `frame` reste declaree, gardee par
     * ses permissions et comptee par l'administration. Elle quitte seulement la
     * grille — une barre de six outils ne dit plus ce qu'on fait dans la Boucle,
     * elle donne une boite a outils au lieu d'une intention.
     *
     * Une Card sans `component` ni `view` est declaree mais pas encore
     * construite : elle n'est jamais proposee a l'activation et ne rend rien.
     * C'est ce qui remplace la liste `rendered` tenue a la main.
     *
     * `component` et `view` sont ecrits ici par des developpeurs et verifies
     * contre ce catalogue avant usage : aucune chaine soumise par un
     * utilisateur ne peut designer un composant.
     */
    'cards' => [
        'core.ai_summary' => [
            'key' => 'core.ai_summary',
            'label_key' => 'loops.cards.ai_summary.label',
            'description_key' => 'loops.cards.ai_summary.description',
            'empty_title_key' => 'loops.cards.ai_summary.empty_title',
            'empty_body_key' => 'loops.cards.ai_summary.empty_body',
            'action_key' => 'loops.cards.ai_summary.action',
            'icon' => 'sparkles',
            'component' => 'loop-ai-summary-card',
            'view' => null,
            'view_permission' => null,
            'required' => false,
            'order' => 10,
            // Le resume est une action de la conversation, pas un outil au meme
            // titre que la Roadmap : il rejoint les actions IA de ChatLoop.
            'placement' => 'chat_action',
            'category' => 'ai',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => false,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        'core.manifesto' => [
            'key' => 'core.manifesto',
            'label_key' => 'loops.cards.manifesto.label',
            'description_key' => 'loops.cards.manifesto.description',
            'empty_title_key' => 'loops.cards.manifesto.empty_title',
            'empty_body_key' => 'loops.cards.manifesto.empty_body',
            'action_key' => 'loops.cards.manifesto.action',
            'icon' => 'flag',
            'component' => 'loop-manifesto-card',
            'view' => null,
            'view_permission' => 'manifesto.view',
            'required' => false,
            'order' => 20,
            // Cadre permanent : le texte de reference se consulte, il ne
            // s'« ouvre » pas comme un instrument de travail.
            'placement' => 'frame',
            'category' => 'foundation',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => false,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            // TASK-1332 : plus aucun type ne l'impose par defaut (voir
            // config/loop_types.php) — reste au catalogue, activable a tout
            // moment. Ce champ ne pilote rien lui-meme (aucune lecture
            // applicative hors un test de forme) ; corrige ici pour ne pas
            // mentir sur l'intention.
            'default_enabled' => false,
        ],
        'core.roadmap' => [
            'key' => 'core.roadmap',
            'label_key' => 'loops.cards.roadmap.label',
            'description_key' => 'loops.cards.roadmap.description',
            'empty_title_key' => 'loops.cards.roadmap.empty_title',
            'empty_body_key' => 'loops.cards.roadmap.empty_body',
            'action_key' => 'loops.cards.roadmap.action',
            'icon' => 'map',
            'component' => 'loop-roadmap-card',
            'view' => null,
            'view_permission' => 'roadmap.view',
            'required' => false,
            'order' => 30,
            'placement' => 'grid',
            'category' => 'action',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        'core.polls' => [
            'key' => 'core.polls',
            'label_key' => 'loops.cards.polls.label',
            'description_key' => 'loops.cards.polls.description',
            'empty_title_key' => 'loops.cards.polls.empty_title',
            'empty_body_key' => 'loops.cards.polls.empty_body',
            'action_key' => 'loops.cards.polls.action',
            'icon' => 'bars',
            'component' => 'loop-polls-card',
            'view' => null,
            'view_permission' => 'polls.view',
            'required' => false,
            'order' => 35,
            'placement' => 'grid',
            'category' => 'decision',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        'core.events' => [
            'key' => 'core.events',
            'label_key' => 'loops.cards.events.label',
            'description_key' => 'loops.cards.events.description',
            'empty_title_key' => 'loops.cards.events.empty_title',
            'empty_body_key' => 'loops.cards.events.empty_body',
            'action_key' => 'loops.cards.events.action',
            'icon' => 'calendar',
            'component' => 'loop-events-card',
            'view' => null,
            'view_permission' => 'events.view',
            'required' => false,
            'order' => 37,
            'placement' => 'grid',
            'category' => 'rhythm',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
        // ── Dossiers ────────────────────────────────────────────────────────
        //
        // Cette Card ne cree aucun systeme documentaire : elle donne une fenetre
        // sur le Dossier racine que toute Boucle possede deja
        // (`dossiers.loop_id`, pose par LoopRootDocumentService a la creation).
        // Articles, Series et fichiers restent la ou ils sont, avec leurs
        // routes, leur policy et leur editeur.
        'core.dossiers' => [
            'key' => 'core.dossiers',
            'label_key' => 'loops.cards.dossiers.label',
            'description_key' => 'loops.cards.dossiers.description',
            'empty_title_key' => 'loops.cards.dossiers.empty_title',
            'empty_body_key' => 'loops.cards.dossiers.empty_body',
            'action_key' => 'loops.cards.dossiers.action',
            'icon' => 'folder',
            'component' => 'loop-dossiers-card',
            'view' => null,
            'view_permission' => 'dossiers.view',
            'required' => false,
            // Apres Evenements (37), avant Membres (40) : la Card documentaire
            // ferme la rangee distinctive.
            'order' => 38,
            'placement' => 'grid',
            'category' => 'documentary',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],

        /*
         * Le Support de cours d'une Boucle Formation.
         *
         * C'est **une** Card, et une seule. Ses Modules et ses Sequences n'en
         * sont pas : ce sont ses contenus, comme un Article est le contenu d'un
         * Dossier. Les declarer ici aurait fait trois Cards la ou la matrice
         * canonique n'en prevoit qu'une, et aurait mange deux des trois
         * emplacements de la grille.
         *
         * `scope` vaut `training` : contrairement aux Cards universelles, elle
         * n'a de sens que dans une Boucle Formation.
         *
         * Ce champ est **descriptif**, et il faut le dire : `scopeOf()` n'a
         * qu'un lecteur, `LoopPresetConfigurator`, qui l'expose sans filtrer.
         * Le confinement reel vient du **preset** de `config/loop_types.php`,
         * ou cette Card n'est listee que sous `training`. Un administrateur
         * composant a la main pourrait donc l'activer ailleurs — elle y
         * fonctionnerait, sans rien casser, mais sans rien vouloir dire.
         *
         * Aucune vue ne teste `$loop->type`, et c'est ce qui compte : la
         * composition reste declarative.
         */
        'training.course_material' => [
            'key' => 'training.course_material',
            'label_key' => 'loops.cards.course_material.label',
            'description_key' => 'loops.cards.course_material.description',
            'empty_title_key' => 'loops.cards.course_material.empty_title',
            'empty_body_key' => 'loops.cards.course_material.empty_body',
            'action_key' => 'loops.cards.course_material.action',
            'icon' => 'academic',
            'component' => 'loop-course-material-card',
            'view' => null,
            'view_permission' => 'course_material.view',
            'required' => false,
            // Premiere Card distinctive de la Formation, avant Membres (40).
            'order' => 32,
            'placement' => 'grid',
            'category' => 'pedagogy',
            'scope' => 'training',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],

        /*
         * La Progression individuelle.
         *
         * **Une seule Card a deux visages** : « Ma progression » pour le
         * stagiaire, la matrice pour l'Animateur. Deux Cards obligeraient a
         * choisir laquelle montrer selon le role, ce que le socle ne sait pas
         * faire — et laisseraient une case vide a qui n'a pas le bon role.
         *
         * Elle n'a de sens qu'avec un Support de cours : `requires` le dit, et
         * le registry refuse de l'activer sans lui.
         */
        'training.progression' => [
            'key' => 'training.progression',
            'label_key' => 'loops.cards.progression.label',
            'description_key' => 'loops.cards.progression.description',
            'empty_title_key' => 'loops.cards.progression.empty_title',
            'empty_body_key' => 'loops.cards.progression.empty_body',
            'action_key' => 'loops.cards.progression.action',
            'icon' => 'trending',
            'component' => 'loop-progression-card',
            'view' => null,
            'view_permission' => 'progression.view',
            'required' => false,
            // Juste apres le Support de cours (32) : on suit ce qu'on a monte.
            'order' => 34,
            'placement' => 'grid',
            'category' => 'pedagogy',
            'scope' => 'training',
            'requires' => ['training.course_material'],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],

        /*
         * Les Travaux a rendre — le **troisieme** emplacement de la Formation.
         *
         * La matrice canonique donne ce slot a « Travaux a rendre **ou** QCM ».
         * Les deux restent deux Cards : un depot avec lecture humaine et un
         * bareme avec tentatives sont deux metiers, et les reunir ferait une
         * Card que personne ne saurait decrire.
         *
         * Elle depend du Support de cours et de la Progression : un Travail
         * ferme une etape d'un parcours, il n'a pas de sens sans lui.
         */
        'training.assignments' => [
            'key' => 'training.assignments',
            'label_key' => 'loops.cards.assignments.label',
            'description_key' => 'loops.cards.assignments.description',
            'empty_title_key' => 'loops.cards.assignments.empty_title',
            'empty_body_key' => 'loops.cards.assignments.empty_body',
            'action_key' => 'loops.cards.assignments.action',
            'icon' => 'clipboard',
            'component' => 'loop-assignments-card',
            'view' => null,
            'view_permission' => 'assignments.view',
            'required' => false,
            'order' => 36,
            'placement' => 'grid',
            'category' => 'pedagogy',
            'scope' => 'training',
            'requires' => ['training.course_material', 'training.progression'],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],

        /*
         * Le QCM — l'autre occupant possible du troisieme emplacement.
         *
         * La matrice canonique dit « Travaux a rendre **ou** QCM ». Les deux
         * restent deux Cards : un depot avec lecture humaine et un bareme avec
         * tentatives sont deux metiers. Les deux peuvent coexister localement,
         * mais la grille n'en montre que trois — c'est au formateur de choisir
         * sa composition.
         */
        'training.quiz' => [
            'key' => 'training.quiz',
            'label_key' => 'loops.cards.quiz.label',
            'description_key' => 'loops.cards.quiz.description',
            'empty_title_key' => 'loops.cards.quiz.empty_title',
            'empty_body_key' => 'loops.cards.quiz.empty_body',
            'action_key' => 'loops.cards.quiz.action',
            'icon' => 'question',
            'component' => 'loop-quiz-card',
            'view' => null,
            'view_permission' => 'quiz.view',
            'required' => false,
            // 39 et non 36-adjacent : `core.events` occupe 37 et
            // `core.dossiers` 38. Deux Cards ne peuvent pas partager un ordre —
            // il fixe la position dans la grille, et un ex aequo la rendrait
            // dependante de l'ordre de declaration.
            'order' => 39,
            'placement' => 'grid',
            'category' => 'pedagogy',
            'scope' => 'training',
            'requires' => ['training.course_material', 'training.progression'],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => false,
        ],

        /*
         * Le Journal — ce qui s'est passe, date et signe.
         *
         * **Deux presets l'attendent** : Pair-aidance et Coaching. C'est ce qui
         * en fait la Card a livrer en premier apres la Formation — une seule
         * table sert deux parcours.
         *
         * Elle ne depend d'aucune autre : un Journal se tient seul.
         */
        'core.journal' => [
            'key' => 'core.journal',
            'label_key' => 'loops.cards.journal.label',
            'description_key' => 'loops.cards.journal.description',
            'empty_title_key' => 'loops.cards.journal.empty_title',
            'empty_body_key' => 'loops.cards.journal.empty_body',
            'action_key' => 'loops.cards.journal.action',
            'icon' => 'book',
            'component' => 'loop-journal-card',
            'view' => null,
            'view_permission' => 'journal.view',
            'required' => false,
            // Entre Sondages (35) et Evenements (37) : le Journal se lit au
            // rythme de la Boucle, comme eux.
            'order' => 41,
            'placement' => 'grid',
            'category' => 'rhythm',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],

        /*
         * Les Decisions : ce qui a ete tranche, quand, et pourquoi.
         *
         * Le North Star nomme la perte qu'elle adresse — « une decision n'est
         * pas transformee en action » — et la regle qui la gouverne :
         * « l'humain reste responsable des decisions durables ».
         *
         * `order` 31, juste apres la Roadmap (30) et avant les Sondages : la
         * matrice produit lit « Roadmap · Decisions · Dossiers » pour le
         * Projet, et l'ordre d'affichage suit cette lecture.
         *
         * Elle ne depend d'aucune autre Card. Le lien vers la Roadmap est un
         * **service rendu quand elle est la**, pas une dependance : une Boucle
         * qui n'aurait que les Decisions les consigne quand meme.
         */
        'core.decisions' => [
            'key' => 'core.decisions',
            'label_key' => 'loops.cards.decisions.label',
            'description_key' => 'loops.cards.decisions.description',
            'empty_title_key' => 'loops.cards.decisions.empty_title',
            'empty_body_key' => 'loops.cards.decisions.empty_body',
            'action_key' => 'loops.cards.decisions.action',
            'icon' => 'scale',
            'component' => 'loop-decisions-card',
            'view' => null,
            'view_permission' => 'decisions.view',
            'required' => false,
            'order' => 31,
            'placement' => 'grid',
            'category' => 'rhythm',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],

        /*
         * Demande-Offre : ce que la Boucle met en avant du catalogue.
         *
         * **Aucun second systeme.** Les Offres (`services`) et les Demandes
         * (`service_requests`) existent depuis l'origine du produit. Cette Card
         * ne fait que les rattacher a une Boucle — elle ne porte meme pas de
         * formulaire de creation, le parcours existant ayant ses regles de
         * categorie, de mode de livraison et de cout en points.
         *
         * `order` 33, entre le Support de cours (32) et la Progression (34) :
         * un emplacement libre, l'ordre n'ayant de sens qu'entre Cards
         * effectivement presentes dans un meme preset.
         */
        'core.marketplace' => [
            'key' => 'core.marketplace',
            'label_key' => 'loops.cards.marketplace.label',
            'description_key' => 'loops.cards.marketplace.description',
            'empty_title_key' => 'loops.cards.marketplace.empty_title',
            'empty_body_key' => 'loops.cards.marketplace.empty_body',
            'action_key' => 'loops.cards.marketplace.action',
            'icon' => 'swap',
            'component' => 'loop-marketplace-card',
            'view' => null,
            'view_permission' => 'marketplace.view',
            'required' => false,
            'order' => 33,
            'placement' => 'grid',
            'category' => 'rhythm',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],

        /*
         * Article — l'atelier d'ecriture d'une Boucle de Redaction.
         *
         * **Aucun second systeme.** Les Articles sont des `BlogPost` ranges
         * dans le Dossier de la Boucle ; l'editeur, les audiences, les
         * snapshots, les co-auteurs et les Series existent depuis longtemps.
         * Cette Card ne fait que les **lire sous un autre angle**, et renvoie
         * aux parcours existants pour ecrire.
         *
         * **Distincte de Dossiers**, que la matrice nomme separement : le
         * Dossier est le classeur — ce que la Boucle range — quand l'atelier
         * repond a « qu'est-ce que j'ecris, et qu'est-ce qui attend ». Un
         * brouillon commence il y a trois semaines n'apparait plus dans un
         * classeur trie par date ; c'est pourtant ce qu'on cherche en revenant
         * ecrire.
         *
         * `order` 42 : les emplacements 30 a 41 sont pris, et l'ordre n'a de
         * sens qu'entre Cards effectivement presentes dans un meme preset.
         */
        'core.article' => [
            'key' => 'core.article',
            'label_key' => 'loops.cards.article.label',
            'description_key' => 'loops.cards.article.description',
            'empty_title_key' => 'loops.cards.article.empty_title',
            'empty_body_key' => 'loops.cards.article.empty_body',
            'action_key' => 'loops.cards.article.action',
            'icon' => 'pencil',
            'component' => 'loop-article-card',
            'view' => null,
            'view_permission' => 'writing.view',
            'required' => false,
            'order' => 42,
            'placement' => 'grid',
            'category' => 'rhythm',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => true,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],

        'core.members' => [
            'key' => 'core.members',
            'label_key' => 'loops.cards.members.label',
            'description_key' => 'loops.cards.members.description',
            'empty_title_key' => 'loops.cards.members.empty_title',
            'empty_body_key' => 'loops.cards.members.empty_body',
            'action_key' => 'loops.cards.members.action',
            'icon' => 'users',
            // Un partiel Blade et non un composant Livewire : cette Card lit des
            // donnees deja preparees par le controleur de la page.
            'component' => null,
            'view' => 'loops.cards.members',
            'view_permission' => 'loop_members.view',
            // Sans les membres, un workspace n'a plus de sens.
            'required' => true,
            'order' => 40,
            // Cadre permanent : savoir qui est la est un contexte, pas une
            // activite.
            'placement' => 'frame',
            'category' => 'foundation',
            'scope' => 'universal',
            'requires' => [],
            'incompatible_with' => [],
            'replaceable' => false,
            'permission' => 'loop.active_member',
            'mobile' => 'drawer',
            'default_enabled' => true,
        ],
    ],
];
